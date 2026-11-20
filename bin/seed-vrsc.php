<?php

/**
 * Jeu de données de démonstration VRSC : simulation de l'activité du 01/01/2026 à la veille du jour courant.
 *   php bin/seed-vrsc.php            (base sans site uniquement)
 *
 * L'activité est rejouée jour par jour en passant par les services métier, sous l'identité de chaque rôle,
 * avec une horloge simulée (PHP et MariaDB) : circuits de validation, coûts moyens, compteurs, clôtures et journal
 * sont donc cohérents et datés. Les comptes créés partagent le mot de passe affiché à la fin.
 */

declare(strict_types=1);

// --- Horloge simulée : les appels date() / time() / strtotime() du code applicatif lisent SeedClock. -----------------
namespace App\Core { function date(string $f, ?int $t = null): string { return \date($f, $t ?? \SeedClock::$now); } function time(): int { return \SeedClock::$now; } function strtotime(string $s, ?int $b = null): int|false { return \strtotime($s, $b ?? \SeedClock::$now); } }
namespace App\Domain { function date(string $f, ?int $t = null): string { return \date($f, $t ?? \SeedClock::$now); } function time(): int { return \SeedClock::$now; } function strtotime(string $s, ?int $b = null): int|false { return \strtotime($s, $b ?? \SeedClock::$now); } }
namespace App\Security { function date(string $f, ?int $t = null): string { return \date($f, $t ?? \SeedClock::$now); } function time(): int { return \SeedClock::$now; } function strtotime(string $s, ?int $b = null): int|false { return \strtotime($s, $b ?? \SeedClock::$now); } }
namespace App\Repositories { function date(string $f, ?int $t = null): string { return \date($f, $t ?? \SeedClock::$now); } function time(): int { return \SeedClock::$now; } function strtotime(string $s, ?int $b = null): int|false { return \strtotime($s, $b ?? \SeedClock::$now); } }
namespace App\Services { function date(string $f, ?int $t = null): string { return \date($f, $t ?? \SeedClock::$now); } function time(): int { return \SeedClock::$now; } function strtotime(string $s, ?int $b = null): int|false { return \strtotime($s, $b ?? \SeedClock::$now); } }
namespace App\Controllers { function date(string $f, ?int $t = null): string { return \date($f, $t ?? \SeedClock::$now); } function time(): int { return \SeedClock::$now; } function strtotime(string $s, ?int $b = null): int|false { return \strtotime($s, $b ?? \SeedClock::$now); } }

namespace {

use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Services\ApprovalWorkflow;
use App\Services\CashService;
use App\Services\EquipmentCostService;
use App\Services\EquipmentDocumentService;
use App\Services\EquipmentService;
use App\Services\FleetTransferService;
use App\Services\PaymentService;
use App\Services\PreventiveService;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseRequestService;
use App\Services\StockInventoryService;
use App\Services\StockPeriodService;
use App\Services\StockService;
use App\Services\StockTransferService;
use App\Services\WorkforceService;
use App\Services\WorkOrderService;

final class SeedClock
{
    public static int $now = 0;
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$db = new Database($config['db']);
if ((int) $db->fetchValue('SELECT COUNT(*) FROM sites') > 0) {
    fwrite(STDERR, "La base contient déjà des sites : génération annulée (base « sans site » attendue).\n");
    exit(1);
}
@set_time_limit(0);
ini_set('memory_limit', '1024M');
mt_srand(20260101);

const PASSWORD = 'Demo-Vrsc-2026';
const START = '2026-01-02';
$end = date('Y-m-d', strtotime('yesterday'));

/** Place l'horloge simulée (PHP et MariaDB). */
function at(string $datetime): void
{
    global $db;
    SeedClock::$now = (int) strtotime($datetime);
    $db->pdo()->exec('SET timestamp = ' . SeedClock::$now);
}

/** Application ouverte au nom d'un compte (une par compte, réutilisée). */
function app(int $userId): App
{
    global $config, $db;
    static $apps = [];

    return $apps[$userId] ??= App::create($config, Session::memory(['auth_user_id' => $userId]), $db, new Request('CLI', '/'));
}

/** @template T @param class-string<T> $class @return T */
function svc(int $userId, string $class): object
{
    return app($userId)->container()->get($class);
}

$errors = [];
$stats = [];
/** Exécute une action ; un refus métier est compté, jamais bloquant. */
function attempt(string $label, callable $action): mixed
{
    global $errors;
    try {
        return $action();
    } catch (\App\Core\ValidationException $e) {
        $errors[$label . ' : ' . implode(' | ', $e->errors)] = ($errors[$label . ' : ' . implode(' | ', $e->errors)] ?? 0) + 1;
    } catch (\Throwable $e) {
        $key = $label . ' : ' . get_class($e) . ' ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
        $errors[$key] = ($errors[$key] ?? 0) + 1;
    }

    return null;
}

function tally(string $key, int $n = 1): void
{
    global $stats;
    $stats[$key] = ($stats[$key] ?? 0) + $n;
}

function chance(float $p): bool
{
    return mt_rand() / mt_getrandmax() < $p;
}

function pick(array $items): mixed
{
    return $items[array_rand($items)];
}

function between(float $min, float $max, int $decimals = 0): float
{
    return round($min + (mt_rand() / mt_getrandmax()) * ($max - $min), $decimals);
}

/** Décision sur l'étape courante d'une demande d'approbation. */
function decide(int $userId, string $entity, int $entityId, string $decision = 'favorable', ?string $comment = null): ?array
{
    global $db;
    $requestId = (int) $db->fetchValue("SELECT id FROM approval_requests WHERE entity_type = :t AND entity_id = :e AND status = 'pending' ORDER BY id DESC LIMIT 1", ['t' => $entity, 'e' => $entityId]);
    if ($requestId === 0) {
        return null;
    }

    return attempt("validation $entity", static fn () => svc($userId, ApprovalWorkflow::class)->act($requestId, ['decision' => $decision, 'comment' => $comment]));
}

/** Étape en attente d'une demande (null si close). */
function pendingStep(string $entity, int $entityId): ?string
{
    global $db;
    $step = $db->fetchValue(
        "SELECT st.code FROM approval_requests r JOIN approval_flow_steps st ON st.id = r.current_step_id
          WHERE r.entity_type = :t AND r.entity_id = :e AND r.status = 'pending' ORDER BY r.id DESC LIMIT 1",
        ['t' => $entity, 'e' => $entityId]
    );

    return $step !== null ? (string) $step : null;
}

$proofPath = sys_get_temp_dir() . '/vrsc-seed-preuve.pdf';
file_put_contents($proofPath, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
function proof(string $name): array
{
    global $proofPath;

    return ['tmp_name' => $proofPath, 'name' => $name, 'size' => filesize($proofPath), 'error' => UPLOAD_ERR_OK];
}

// =====================================================================================================================
// 1. Mise en place (2 janvier 2026)
// =====================================================================================================================
at(START . ' 07:30:00');
echo "Mise en place des référentiels, sites, comptes et parc…\n";

$sites = [
    'LUB' => ['Siège Lubumbashi', 'office', 'Lubumbashi'],
    'KOL' => ['Chantier Kolwezi', 'earthmoving', 'Kolwezi'],
    'LIK' => ['Chantier Likasi', 'earthmoving', 'Likasi'],
    'KAM' => ['Base transport Kambove', 'transport', 'Kambove'],
];
$siteId = [];
foreach ($sites as $code => [$name, $activity, $city]) {
    $siteId[$code] = $db->insert('INSERT INTO sites (code, name, activity, city) VALUES (:c, :n, :a, :v)', ['c' => $code, 'n' => $name, 'a' => $activity, 'v' => $city]);
}
$operating = ['KOL', 'LIK', 'KAM'];

$hash = password_hash(PASSWORD, PASSWORD_DEFAULT);
$people = [];
/** Crée un compte ; $sitesFor = [] et $all = true pour tous les sites. */
$user = static function (string $first, string $last, string $role, array $sitesFor, bool $all = false) use ($db, $hash, $siteId, &$people): int {
    $email = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $first . '.' . $last)) . '@vrsc.test';
    $email = preg_replace('/[^a-z0-9.@]/', '', $email);
    $id = $db->insert(
        'INSERT INTO users (email, password_hash, first_name, last_name, role, all_sites) VALUES (:e, :h, :f, :l, :r, :a)',
        ['e' => $email, 'h' => $hash, 'f' => $first, 'l' => $last, 'r' => $role, 'a' => $all ? 1 : 0]
    );
    foreach ($sitesFor as $code) {
        $db->execute('INSERT INTO user_sites (user_id, site_id) VALUES (:u, :s)', ['u' => $id, 's' => $siteId[$code]]);
    }
    $people[] = [$email, $role, $all ? 'tous' : implode(', ', $sitesFor)];

    return $id;
};
$u = [
    'logistics' => $user('Patrick', 'Mukendi', 'logistics', [], true),
    'finance' => $user('Sandrine', 'Kalala', 'finance', [], true),
    'director' => $user('Albert', 'Ngoy', 'director', [], true),
    'buyer' => $user('Josué', 'Ilunga', 'buyer', [], true),
    'viewer' => $user('Esther', 'Mbuyi', 'viewer', [], true),
];
$names = [
    'KOL' => [['Didier', 'Kabeya'], ['Jean', 'Kasongo'], ['Henri', 'Mwamba'], ['Ruth', 'Tshibangu'], ['Moïse', 'Kapend'], ['Grâce', 'Nawej'], ['Paul', 'Banza']],
    'LIK' => [['Serge', 'Mutombo'], ['Cédric', 'Kazadi'], ['Joël', 'Mulumba'], ['Aline', 'Kabila'], ['Eric', 'Lukusa'], ['Nadine', 'Mbayo'], ['Olivier', 'Kisimba']],
    'KAM' => [['Fabrice', 'Kanku'], ['Trésor', 'Kapinga'], ['Bienvenu', 'Masengo'], ['Chantal', 'Mujinga'], ['Junior', 'Kalenga'], ['Rose', 'Kyungu'], ['Dieudonné', 'Mbala']],
    'LUB' => [['Christian', 'Kalombo'], [], [], ['Mireille', 'Kanyinda'], ['Gédéon', 'Lumbala'], ['Béatrice', 'Ngalula'], ['Arnaud', 'Kibwe']],
];
$roles = ['manager', 'technician', 'storekeeper', 'requester', 'supervisor', 'cashier', 'encoder'];
$site = [];
foreach ($names as $code => $list) {
    foreach ($roles as $i => $role) {
        if (($list[$i] ?? []) !== []) {
            $site[$code][$role] = $user($list[$i][0], $list[$i][1], $role, [$code]);
        }
    }
}
// Le siège : son magasinier est celui de Kolwezi (magasin central réduit), son technicien celui de Kolwezi.
$site['LUB']['storekeeper'] = $user('Laurent', 'Kayembe', 'storekeeper', ['LUB']);
$site['LUB']['technician'] = $site['KOL']['technician'];
$admin = (int) $db->fetchValue("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");

// Référentiels (Direction Logistique).
$ref = static fn (string $type, array $data) => (int) attempt("référentiel $type", static fn () => svc($u['logistics'], \App\Services\ReferenceService::class)->create($type, $data + ['is_active' => '1']));
foreach ([['MAINT', 'Maintenance'], ['LOG', 'Logistique'], ['EXP', 'Exploitation'], ['ADM', 'Administration & finance']] as [$c, $n]) {
    $ref('departments', ['code' => $c, 'name' => $n]);
}
$cc = [];
foreach ([['CC-KOL', 'Chantier Kolwezi'], ['CC-LIK', 'Chantier Likasi'], ['CC-KAM', 'Transport Kambove'], ['CC-SIE', 'Siège']] as [$c, $n]) {
    $cc[$c] = $ref('cost-centers', ['code' => $c, 'name' => $n]);
}
$project = [
    'KOL' => $ref('projects', ['code' => 'PRJ-KOL-01', 'name' => 'Découverture fosse Mutoshi', 'site_id' => $siteId['KOL'], 'project_kind' => 'worksite', 'cost_center_id' => $cc['CC-KOL'], 'starts_on' => '2025-09-01']),
    'KOL2' => $ref('projects', ['code' => 'PRJ-KOL-02', 'name' => 'Pistes et digues à résidus', 'site_id' => $siteId['KOL'], 'project_kind' => 'project', 'cost_center_id' => $cc['CC-KOL'], 'starts_on' => '2026-02-01']),
    'LIK' => $ref('projects', ['code' => 'PRJ-LIK-01', 'name' => 'Route Likasi–Kambove (lot 2)', 'site_id' => $siteId['LIK'], 'project_kind' => 'project', 'cost_center_id' => $cc['CC-LIK'], 'starts_on' => '2025-11-15']),
    'KAM' => $ref('projects', ['code' => 'PRJ-KAM-01', 'name' => 'Navettes du personnel minier', 'site_id' => $siteId['KAM'], 'project_kind' => 'worksite', 'cost_center_id' => $cc['CC-KAM'], 'starts_on' => '2025-06-01']),
];
foreach ($operating as $code) {
    $ref('warehouses', ['code' => 'MAG-' . $code, 'name' => 'Magasin ' . $sites[$code][2], 'site_id' => $siteId[$code], 'location' => 'Base vie']);
}
$ref('warehouses', ['code' => 'MAG-LUB', 'name' => 'Magasin central Lubumbashi', 'site_id' => $siteId['LUB'], 'is_central' => '1']);
$suppliers = [];
foreach ([
    ['FRN-KD', 'Katanga Diesel SARL', 'fuel'], ['FRN-ENG', 'Engen RDC', 'fuel'], ['FRN-CPE', 'Congo Pièces Engins', 'parts'],
    ['FRN-MPL', 'Mining Parts Lubumbashi', 'parts'], ['FRN-TCK', 'Tyre Center Katanga', 'tyres'], ['FRN-LUK', 'Lubrifiants du Katanga', 'lubricants'],
    ['FRN-GAK', 'Garage Atelier Kolwezi', 'services'],
] as [$c, $n, $kind]) {
    $suppliers[$kind][] = $ref('suppliers', ['code' => $c, 'name' => $n, 'contact_name' => 'Service commercial', 'phone' => '+243 99 ' . mt_rand(100, 999) . ' ' . mt_rand(1000, 9999)]);
}
$carrier = $ref('carriers', ['code' => 'TRP-KAT', 'name' => 'Transports du Katanga', 'contact_name' => 'Dispatch']);
$method = array_column($db->fetchAll('SELECT id, code FROM payment_methods'), 'id', 'code');

// Consommation de référence par modèle (L/h ou L/100 km) : active les alertes de consommation.
foreach (['PC200-8' => 16, 'PC350LC-8' => 24, '336D2' => 26, 'EC380DL' => 25, 'D8R' => 34, 'D65EX-16' => 22, 'WA470-6' => 19, '950H' => 17, 'L120H' => 15,
    'HM400-3' => 28, 'A40G' => 27, 'B40E' => 26, '140K' => 15, 'CS74' => 12, 'FMX 440 6x4' => 42, 'HOWO ZZ3257 371' => 40, 'ZZ3257 371' => 40,
    'ZK6122H9' => 30, 'Coaster' => 16, 'Hilux' => 11, 'Land Cruiser 79' => 14, 'ZZ1257 Citerne' => 38] as $model => $refValue) {
    $db->execute('UPDATE equipment_models SET fuel_reference = :r WHERE name = :n', ['r' => $refValue, 'n' => $model]);
}
$model = array_column($db->fetchAll('SELECT m.id, m.name FROM equipment_models m'), 'id', 'name');

// Articles et stock d'ouverture.
// [référence, libellé, type, unité, prix, [stock d'ouverture KOL, LIK, KAM, LUB], seuil mini, quantité de réappro]
$catalog = [
    ['GO-001', 'Gasoil (cuve chantier)', 'fuel', 'L', 1.32, [45000, 42000, 14000, 2500], ['KOL' => 24000, 'LIK' => 22000, 'KAM' => 8000, 'LUB' => 1200], ['KOL' => 45000, 'LIK' => 42000, 'KAM' => 16000, 'LUB' => 2500]],
    ['HM-15W40', 'Huile moteur 15W40', 'lubricant', 'L', 4.80, [600, 600, 300, 60], 180, 600],
    ['HH-46', 'Huile hydraulique HV46', 'lubricant', 'L', 4.20, [800, 800, 60, 0], 200, 600],
    ['LIQ-REF', 'Liquide de refroidissement', 'lubricant', 'L', 3.90, [200, 200, 120, 20], 60, 200],
    ['GRAISSE', 'Graisse EP2', 'lubricant', 'kg', 6.50, [180, 180, 40, 0], 50, 150],
    ['FLT-HUI', 'Filtre à huile moteur', 'filter', 'u', 28, [40, 40, 24, 4], 12, 30],
    ['FLT-GO', 'Filtre à gasoil', 'filter', 'u', 35, [40, 40, 24, 4], 12, 30],
    ['FLT-AIR', 'Filtre à air primaire', 'filter', 'u', 62, [20, 20, 12, 2], 6, 16],
    ['FLT-HYD', 'Filtre hydraulique retour', 'filter', 'u', 85, [16, 16, 0, 0], 5, 12],
    ['PNE-2925', 'Pneu 29.5R25 (chargeuse, tombereau)', 'tyre', 'u', 3200, [4, 4, 0, 0], 2, 4],
    ['PNE-1200', 'Pneu 12.00R20 (camion, bus)', 'tyre', 'u', 420, [6, 6, 14, 4], 4, 10],
    ['DENT-GD', 'Dent de godet', 'part', 'u', 145, [24, 24, 0, 0], 8, 20],
    ['PLAQ-FR', 'Jeu de plaquettes de frein', 'part', 'jeu', 95, [6, 6, 16, 4], 4, 10],
    ['BAT-12V', 'Batterie 12 V 200 Ah', 'part', 'u', 260, [6, 6, 6, 2], 2, 6],
    ['FLX-HYD', 'Flexible hydraulique haute pression', 'part', 'u', 120, [14, 14, 2, 0], 4, 12],
    ['CHIF', 'Chiffons industriels', 'consumable', 'kg', 2.10, [80, 80, 40, 10], 20, 60],
];
$part = [];
$partInfo = [];
foreach ($catalog as [$refCode, $label, $type, $unit, $price, $opening, $min, $reorder]) {
    $id = $db->insert('INSERT INTO parts (reference, name, part_type, unit, unit_cost) VALUES (:r, :n, :t, :u, :c)', ['r' => $refCode, 'n' => $label, 't' => $type, 'u' => $unit, 'c' => $price]);
    $part[$refCode] = $id;
    $partInfo[$id] = ['ref' => $refCode, 'name' => $label, 'type' => $type, 'price' => $price, 'min' => $min, 'reorder' => $reorder];
    foreach (['KOL', 'LIK', 'KAM', 'LUB'] as $i => $code) {
        if ($opening[$i] > 0) {
            attempt('stock d\'ouverture', static fn () => svc($site[$code]['storekeeper'], StockService::class)->move($id, $siteId[$code], 'opening', $opening[$i], 'Reprise du stock au 01/01/2026', null, null,
                ['unit_cost' => $price * between(0.95, 1.05, 2), 'moved_on' => '2026-01-01', 'document_ref' => 'OUV-2026']));
        }
        $threshold = is_array($min) ? ($min[$code] ?? 0) : ($opening[$i] > 0 ? $min : 0);
        $db->execute('UPDATE part_stocks SET min_quantity = :m WHERE part_id = :p AND site_id = :s', ['m' => $threshold, 'p' => $id, 's' => $siteId[$code]]);
    }
}
tally('articles', count($catalog));

// Personnel : mécaniciens (taux horaire), conducteurs et opérateurs.
$mechanics = [];
$operators = [];
foreach ($operating as $code) {
    $ws = svc($site[$code]['manager'], WorkforceService::class);
    foreach ([['Kitenge', 'mechanic', 12.5], ['Mbuyu', 'mechanic', 11], ['Kasanda', 'electrician', 13], ['Ilunga', 'welder', 10.5], ['Kabongo', 'tyre_fitter', 9]] as $i => [$last, $job, $rate]) {
        $pid = (int) attempt('personnel', static fn () => $ws->createPerson(['site_id' => $siteId[$code], 'employee_no' => $code . '-M' . ($i + 1), 'first_name' => pick(['Jean', 'Pierre', 'Joseph', 'André', 'Marc', 'Luc']),
            'last_name' => $last, 'job' => $job, 'hourly_rate' => (string) $rate, 'is_active' => '1']));
        $mechanics[$code][] = $pid;
    }
    // Un mécanicien sans taux horaire : sa main-d'œuvre apparaîtra « manquante » dans les coûts.
    $mechanics[$code][] = (int) attempt('personnel', static fn () => $ws->createPerson(['site_id' => $siteId[$code], 'employee_no' => $code . '-M9', 'first_name' => 'Stagiaire', 'last_name' => 'Mwenze', 'job' => 'mechanic', 'is_active' => '1']));
    for ($i = 1; $i <= 6; $i++) {
        $operators[$code][] = (int) attempt('personnel', static fn () => $ws->createPerson(['site_id' => $siteId[$code], 'employee_no' => $code . '-C' . $i, 'first_name' => pick(['Daniel', 'Samuel', 'Emmanuel', 'Jacques', 'Patrice', 'Félix']),
            'last_name' => pick(['Mukanya', 'Kabwe', 'Tshala', 'Kaumba', 'Mbiya', 'Ngandu']), 'job' => $code === 'KAM' ? 'driver' : 'operator', 'phone' => '+243 97 ' . mt_rand(100, 999) . ' ' . mt_rand(1000, 9999),
            'license_number' => 'PC-' . mt_rand(10000, 99999), 'hourly_rate' => (string) between(5, 8, 1), 'is_active' => '1']));
    }
}

// Parc : [code, nom, modèle, site, compteur initial, propriété, projet, année, puissance, unité, capacité, unité, couleur, acquisition]
$fleet = [
    ['KOL-PEL-01', 'Pelle 35 t', 'PC350LC-8', 'KOL', 8420, 'owned', 'KOL', 2019, 257, 'hp', 2.2, 'm3', 'Jaune', '2019-05-10'],
    ['KOL-PEL-02', 'Pelle 38 t', 'EC380DL', 'KOL', 5120, 'owned', 'KOL', 2021, 296, 'hp', 2.5, 'm3', 'Jaune', '2021-03-02'],
    ['KOL-PEL-03', 'Pelle 20 t', 'PC200-8', 'KOL', 11250, 'owned', 'KOL2', 2016, 148, 'hp', 1.0, 'm3', 'Jaune', '2016-08-20'],
    ['KOL-BUL-01', 'Bulldozer D8', 'D8R', 'KOL', 14800, 'owned', 'KOL', 2015, 310, 'hp', null, null, 'Jaune', '2015-11-04'],
    ['KOL-CHG-01', 'Chargeuse sur pneus', 'WA470-6', 'KOL', 9630, 'owned', 'KOL', 2018, 272, 'hp', 4.7, 'm3', 'Jaune', '2018-02-14'],
    ['KOL-ADT-01', 'Tombereau articulé 40 t', 'A40G', 'KOL', 6200, 'owned', 'KOL', 2020, 469, 'hp', 39, 't', 'Jaune', '2020-06-30'],
    ['KOL-ADT-02', 'Tombereau articulé 40 t', 'A40G', 'KOL', 6780, 'rental', 'KOL', 2020, 469, 'hp', 39, 't', 'Jaune', null],
    ['KOL-ADT-03', 'Tombereau articulé 40 t', 'B40E', 'KOL', 3400, 'owned', 'KOL2', 2022, 449, 'hp', 39, 't', 'Jaune', '2022-09-12'],
    ['KOL-NIV-01', 'Niveleuse', '140K', 'KOL', 7300, 'owned', 'KOL2', 2017, 183, 'hp', null, null, 'Jaune', '2017-04-01'],
    ['LIK-PEL-01', 'Pelle 36 t', '336D2', 'LIK', 7900, 'owned', 'LIK', 2018, 268, 'hp', 2.3, 'm3', 'Jaune', '2018-07-18'],
    ['LIK-PEL-02', 'Pelle 20 t', 'PC200-8', 'LIK', 9800, 'owned', 'LIK', 2017, 148, 'hp', 1.0, 'm3', 'Jaune', '2017-01-25'],
    ['LIK-BUL-01', 'Bulldozer D65', 'D65EX-16', 'LIK', 10400, 'owned', 'LIK', 2017, 220, 'hp', null, null, 'Jaune', '2017-03-09'],
    ['LIK-CHG-01', 'Chargeuse 950H', '950H', 'LIK', 12100, 'owned', 'LIK', 2014, 197, 'hp', 3.1, 'm3', 'Jaune', '2014-10-15'],
    ['LIK-CHG-02', 'Chargeuse L120H', 'L120H', 'LIK', 4300, 'owned', 'LIK', 2021, 250, 'hp', 3.4, 'm3', 'Jaune', '2021-12-01'],
    ['LIK-ADT-01', 'Tombereau articulé 40 t', 'HM400-3', 'LIK', 8800, 'owned', 'LIK', 2019, 466, 'hp', 40, 't', 'Jaune', '2019-02-11'],
    ['LIK-ADT-02', 'Tombereau articulé 40 t', 'B40E', 'LIK', 5100, 'rental', 'LIK', 2021, 449, 'hp', 39, 't', 'Jaune', null],
    ['LIK-CMP-01', 'Compacteur monocylindre', 'CS74', 'LIK', 6100, 'owned', 'LIK', 2016, 160, 'hp', 15.6, 't', 'Jaune', '2016-05-23'],
    ['LIK-CAM-01', 'Camion benne 6x4', 'ZZ3257 371', 'LIK', 182400, 'owned', 'LIK', 2019, 371, 'hp', 25, 't', 'Blanc', '2019-09-05'],
    ['KAM-BUS-01', 'Bus 60 places', 'ZK6122H9', 'KAM', 214500, 'owned', 'KAM', 2018, 336, 'hp', 60, 'seats', 'Blanc', '2018-01-19'],
    ['KAM-BUS-02', 'Bus 60 places', 'ZK6122H9', 'KAM', 188300, 'owned', 'KAM', 2019, 336, 'hp', 60, 'seats', 'Blanc', '2019-06-02'],
    ['KAM-BUS-03', 'Minibus 29 places', 'Coaster', 'KAM', 241800, 'owned', 'KAM', 2017, 148, 'hp', 29, 'seats', 'Blanc', '2017-04-27'],
    ['KAM-CAM-01', 'Camion benne 6x4', 'FMX 440 6x4', 'KAM', 156200, 'owned', 'KAM', 2020, 440, 'hp', 25, 't', 'Bleu', '2020-08-14'],
    ['KAM-CIT-01', 'Camion citerne gasoil', 'ZZ1257 Citerne', 'KAM', 132900, 'owned', 'KAM', 2020, 336, 'hp', 18000, 'l', 'Blanc', '2020-02-03'],
    ['KAM-VL-01', 'Pick-up de liaison', 'Hilux', 'KAM', 96400, 'owned', 'KAM', 2022, 150, 'hp', 1, 't', 'Blanc', '2022-05-16'],
    ['LUB-VL-01', 'Véhicule de direction', 'Land Cruiser 79', 'LUB', 74200, 'owned', null, 2023, 202, 'hp', null, null, 'Gris', '2023-01-10'],
    ['LUB-VL-02', 'Pick-up logistique', 'Hilux', 'LUB', 118500, 'owned', null, 2021, 150, 'hp', 1, 't', 'Blanc', '2021-07-07'],
];
$eq = [];
foreach ($fleet as [$code, $name, $modelName, $siteCode, $meter, $ownership, $projectKey, $year, $power, $powerUnit, $capacity, $capacityUnit, $color, $acquired]) {
    $modelId = $model[$modelName] ?? null;
    $basis = (string) $db->fetchValue('SELECT COALESCE(c.meter_basis, \'hours\') FROM equipment_models m LEFT JOIN equipment_categories c ON c.id = m.category_id WHERE m.id = :m', ['m' => $modelId]);
    $isKm = $basis === 'km';
    $na = [];
    if ($capacity === null) {
        $na[] = 'capacity';
    }
    if (!$isKm) {
        $na[] = 'plate_number';
    }
    $id = (int) attempt('équipement', static fn () => svc($site[$siteCode]['manager'], EquipmentService::class)->create([
        'site_id' => $siteId[$siteCode], 'model_id' => $modelId, 'code' => $code, 'name' => $name,
        'serial_number' => strtoupper(substr(md5($code), 0, 10)), 'chassis_number' => $isKm ? 'CH' . strtoupper(substr(md5('c' . $code), 0, 12)) : null,
        'plate_number' => $isKm ? mt_rand(1000, 9999) . 'AB' . str_pad((string) mt_rand(1, 26), 2, '0', STR_PAD_LEFT) : null,
        'commissioned_at' => $acquired ?? '2024-01-15', 'acquired_at' => $acquired, 'year_made' => $year, 'power_value' => $power, 'power_unit' => $powerUnit,
        'capacity_value' => $capacity, 'capacity_unit' => $capacityUnit, 'color' => $color,
        'owner_name' => $ownership === 'rental' ? 'Katanga Engins Location' : 'VRSC SARL', 'ownership' => $ownership,
        'contract_ref' => $ownership === 'rental' ? 'LOC-2026-' . substr($code, -2) : null, 'contract_party' => $ownership === 'rental' ? 'Katanga Engins Location' : null,
        'contract_start' => $ownership === 'rental' ? '2026-01-01' : null, 'contract_end' => $ownership === 'rental' ? '2026-12-31' : null,
        'project_id' => $projectKey !== null ? $project[$projectKey] : null,
        $isKm ? 'odometer_km' : 'hour_meter' => $meter, 'not_applicable' => $na,
    ]));
    if ($id === 0) {
        continue;
    }
    $refValue = (float) $db->fetchValue('SELECT fuel_reference FROM equipment_models WHERE id = :m', ['m' => $modelId]);
    $eq[$id] = ['code' => $code, 'site' => $siteCode, 'km' => $isKm, 'meter' => $meter, 'since_refill' => 0.0, 'since_service' => mt_rand(0, 200) * ($isKm ? 40 : 1),
        'ref' => $refValue > 0 ? $refValue : ($isKm ? 30 : 18), 'rental' => $ownership === 'rental', 'kind' => substr($code, 4, 3), 'away' => false];
    tally('équipements');
}
// Conducteurs et opérateurs affectés aux engins.
foreach ($eq as $id => $e) {
    if ($e['site'] !== 'LUB' && $operators[$e['site']] !== []) {
        attempt('affectation', static fn () => svc($site[$e['site']]['manager'], WorkforceService::class)->assign($id, ['personnel_id' => pick($operators[$e['site']]), 'shift' => 'day']));
    }
}

// Documents administratifs, avec échéances variées ; « non applicable » pour les engins de chantier hors route.
$types = array_column($db->fetchAll('SELECT id, code FROM document_types WHERE is_active = 1'), 'id', 'code');
foreach ($eq as $id => $e) {
    $docs = svc($site[$e['site'] === 'LUB' ? 'KOL' : $e['site']]['manager'] ?? $admin, EquipmentDocumentService::class);
    if ($e['site'] === 'LUB') {
        $docs = svc($admin, EquipmentDocumentService::class);
    }
    $offset = mt_rand(20, 330);
    attempt('document', static fn () => $docs->add($id, ['document_type_id' => $types['insurance'], 'issuer' => pick(['SONAS', 'Rawsur', 'Activa']), 'reference' => 'POL-' . mt_rand(100000, 999999),
        'issued_at' => date('Y-m-d', strtotime("2026-01-01 -" . (365 - $offset) . ' days')), 'expires_at' => date('Y-m-d', strtotime("2026-01-01 +$offset days")), 'responsible_id' => $u['logistics']]));
    if ($e['km']) {
        attempt('document', static fn () => $docs->add($id, ['document_type_id' => $types['inspection'], 'issuer' => 'CONTRÔLE TECHNIQUE KATANGA', 'reference' => 'CT-' . mt_rand(1000, 9999),
            'issued_at' => '2025-12-15', 'expires_at' => date('Y-m-d', strtotime('2026-06-15 +' . mt_rand(0, 150) . ' days'))]));
        attempt('document', static fn () => $docs->add($id, ['document_type_id' => $types['vignette'], 'issuer' => 'DGRK', 'reference' => 'VIG-' . mt_rand(1000, 9999), 'issued_at' => '2026-01-05', 'expires_at' => '2026-12-31']));
        if ($e['kind'] === 'BUS') {
            attempt('document', static fn () => $docs->add($id, ['document_type_id' => $types['parking'], 'issuer' => 'Ville de Kambove', 'expires_at' => '2026-12-31']));
        }
    } else {
        foreach (['vignette', 'parking', 'inspection'] as $type) {
            attempt('exemption', static fn () => $docs->exempt($id, ['document_type_id' => $types[$type], 'reason' => 'Engin de chantier, ne circule pas sur la voie publique']));
        }
    }
}

// Caisses par site, approvisionnées.
$register = [];
foreach (['KOL', 'LIK', 'KAM', 'LUB'] as $code) {
    $register[$code] = (int) attempt('caisse', static fn () => svc($site[$code]['cashier'], CashService::class)->createRegister(['site_id' => $siteId[$code], 'name' => 'Caisse ' . $sites[$code][2]]));
    attempt('caisse', static fn () => svc($site[$code]['cashier'], CashService::class)->manualEntry($register[$code], ['direction' => 'in', 'amount' => '2500', 'label' => 'Fonds de caisse initial']));
}

// =====================================================================================================================
// 2. Simulation jour par jour
// =====================================================================================================================
$openWo = [];       // OT en cours : id => [equipment, stage, next, parts, immobilizing, attempts]
$edbs = [];         // EDB suivis : id => [site, stage, next, kind, lines…]
$payments = [];     // demandes de décaissement suivies : id => [stage, next, site, cash]
$transfers = [];    // transferts de stock : id => [stage, next, from, to]
$fleetMoves = [];   // transferts d'engins : id => [equipment, stage, next, from, to]
$reorderOpen = [];  // "site|part" => EDB en cours
$payRef = 1000;
$fleetPlan = ['2026-04-14' => ['KOL-CHG-01', 'LIK'], '2026-08-03' => ['LIK-ADT-01', 'KOL'], '2026-11-16' => ['KOL-PEL-03', 'LIK']];
$byCode = [];
foreach ($eq as $id => $e) {
    $byCode[$e['code']] = $id;
}

/** Ouvre un état de besoins et l'inscrit au suivi. */
$newEdb = static function (string $code, int $by, string $category, string $urgency, string $subject, string $reason, array $lines, string $path, array $extra = []) use (&$edbs, $siteId, $project) {
    $id = (int) attempt('EDB', static fn () => svc($by, PurchaseRequestService::class)->create($extra + [
        'site_id' => $siteId[$code], 'category' => $category, 'urgency' => $urgency, 'subject' => $subject, 'reason' => $reason, 'lines' => $lines,
        'project_id' => $project[$code] ?? null,
    ]));
    if ($id > 0) {
        $edbs[$id] = ['site' => $code, 'stage' => 'approval', 'next' => SeedClock::$now + 86400 * mt_rand(1, 2), 'path' => $path, 'category' => $category];
        tally('états de besoins');
    }

    return $id;
};

$day = strtotime(START);
$last = strtotime($end);
$closedMonths = [];
echo "Simulation du " . START . " au {$end}…\n";
while ($day <= $last) {
    $date = date('Y-m-d', $day);
    $dow = (int) date('N', $day);
    $workday = $dow <= 6;
    $month = substr($date, 0, 7);
    if (substr($date, 8, 2) === '01') {
        echo "  $month\n";
    }

    // --- Matin : exploitation (compteurs, ravitaillements) ---------------------------------------------------------
    at("$date 07:10:00");
    if ($workday) {
        foreach ($eq as $id => &$e) {
            $status = (string) $db->fetchValue('SELECT status FROM equipment WHERE id = :id', ['id' => $id]);
            if ($status !== 'available' || $e['away']) {
                continue;
            }
            $usage = $e['km'] ? ($e['kind'] === 'VL-' || str_starts_with($e['kind'], 'VL') ? between(60, 160) : between(140, 320)) : between(6, 11);
            if ($e['site'] === 'LUB') {
                $usage = between(30, 120);
            }
            $e['meter'] += $usage;
            $e['since_refill'] += $usage;
            $e['since_service'] += $usage;
            // Ravitaillement tous les 2 à 3 jours de travail.
            if ($e['since_refill'] >= ($e['km'] ? 450 : 20) || chance(0.15)) {
                $litres = round($e['since_refill'] * $e['ref'] / ($e['km'] ? 100 : 1) * between(0.9, 1.12, 2), 1);
                // Quelques surconsommations ponctuelles pour animer les alertes.
                if ($e['code'] === 'LIK-CHG-01' && $month >= '2026-09') {
                    $litres = round($litres * 1.35, 1);
                }
                $external = ($e['site'] === 'KAM' || $e['site'] === 'LUB') && chance(0.25);
                $encoder = $site[$e['site']]['encoder'] ?? $site[$e['site']]['storekeeper'];
                $meterValue = (int) round($e['meter']);
                $ok = attempt('ravitaillement ' . $e['site'], static fn () => svc($encoder, EquipmentCostService::class)->refill([
                    'equipment_id' => $id, 'refill_date' => $date, $e['km'] ? 'odometer_km' : 'hour_meter' => $meterValue, 'litres' => max(5, $litres),
                    'source' => $external ? 'external' : 'stock', 'part_id' => $external ? null : $part['GO-001'],
                    'station' => $external ? pick(['Engen Kambove', 'Total Likasi', 'Cobil Lubumbashi']) : null,
                    // Un plein en station sur dix sans prix : coût « manquant » dans le rapport.
                    'unit_price' => $external && !chance(0.1) ? (string) between(1.45, 1.6, 2) : null,
                    'voucher_ref' => 'BON-' . mt_rand(10000, 99999),
                ]));
                if ($ok !== null) {
                    $e['since_refill'] = 0;
                    tally('ravitaillements');
                }
            }
            // Panne (≈ 1 % par jour de travail) : OT correctif immobilisant ouvert par le technicien.
            if ($e['site'] !== 'LUB' && chance(0.011)) {
                $tech = $site[$e['site']]['technician'];
                $title = pick($e['km'] ? ['Fuite d\'air circuit de freinage', 'Démarreur hors service', 'Surchauffe moteur', 'Crevaison et jante voilée', 'Embrayage patine']
                    : ['Fuite hydraulique vérin de flèche', 'Surchauffe moteur', 'Flexible hydraulique éclaté', 'Batterie à plat, démarrage impossible', 'Usure des dents de godet', 'Freins de service inefficaces']);
                $wo = (int) attempt('OT correctif', static fn () => svc($tech, WorkOrderService::class)->create(['equipment_id' => $id, 'title' => $title, 'priority' => pick(['high', 'high', 'critical', 'normal']),
                    'immobilizing' => '1', 'description' => 'Constaté par l\'opérateur en début de poste.']));
                if ($wo > 0) {
                    $openWo[$wo] = ['eq' => $id, 'stage' => 'open', 'next' => strtotime("$date +1 day 08:00"), 'type' => 'corrective', 'title' => $title];
                    tally('OT correctifs');
                }
            } elseif ($e['site'] !== 'LUB' && $e['since_service'] >= ($e['km'] ? 10000 : 250)) {
                // Entretien préventif (vidange) : OT non immobilisant, fait dans la journée.
                $e['since_service'] = 0;
                $manager = $site[$e['site']]['manager'];
                $wo = (int) attempt('OT préventif', static fn () => svc($manager, WorkOrderService::class)->create(['equipment_id' => $id, 'title' => 'Entretien ' . ($e['km'] ? '10 000 km' : '250 h') . ' : vidange et filtres',
                    'type' => 'preventive', 'priority' => 'normal', 'immobilizing' => '0']));
                if ($wo > 0) {
                    $openWo[$wo] = ['eq' => $id, 'stage' => 'preventive', 'next' => strtotime("$date 13:00"), 'type' => 'preventive'];
                    tally('OT préventifs');
                }
            }
        }
        unset($e);
    }

    // --- Journée : ordres de travail ----------------------------------------------------------------------------
    foreach ($openWo as $wo => &$w) {
        if ($w['next'] > strtotime("$date 23:59")) {
            continue;
        }
        $e = $eq[$w['eq']];
        $code = $e['site'];
        $tech = $site[$code]['technician'];
        $orders = svc($tech, WorkOrderService::class);
        at(date('Y-m-d H:i:s', max($w['next'], strtotime("$date 08:00"))));
        if ($w['stage'] === 'preventive') {
            attempt('OT préventif', static fn () => $orders->transition($wo, ['status' => 'in_progress']));
            attempt('OT pièce', static fn () => $orders->consumePart($wo, ['part_id' => $part['HM-15W40'], 'quantity' => (string) ($e['km'] ? 18 : between(22, 36))]));
            attempt('OT pièce', static fn () => $orders->consumePart($wo, ['part_id' => $part['FLT-HUI'], 'quantity' => '1']));
            attempt('OT pièce', static fn () => $orders->consumePart($wo, ['part_id' => $part['FLT-GO'], 'quantity' => '1']));
            if (!$e['km']) {
                attempt('OT pièce', static fn () => $orders->consumePart($wo, ['part_id' => $part['GRAISSE'], 'quantity' => (string) between(2, 5)]));
            }
            attempt('OT intervenant', static fn () => $orders->addTechnician($wo, ['personnel_id' => pick($mechanics[$code]), 'minutes' => (string) mt_rand(90, 180)]));
            at("$date 16:30:00");
            attempt('OT préventif', static fn () => $orders->transition($wo, ['status' => 'done', 'resolution' => 'Vidange moteur, remplacement des filtres huile et gasoil, graissage.',
                $e['km'] ? 'odometer_km' : 'hour_meter' => (string) (int) round($e['meter'])]));
            unset($openWo[$wo]);
            continue;
        }
        if ($w['stage'] === 'open') {
            attempt('OT démarrage', static fn () => $orders->transition($wo, ['status' => 'in_progress']));
            attempt('OT intervenant', static fn () => $orders->addTechnician($wo, ['personnel_id' => pick($mechanics[$code]), 'minutes' => (string) mt_rand(180, 600)]));
            $candidates = $e['km'] ? ['PLAQ-FR', 'BAT-12V', 'PNE-1200', 'FLT-AIR', 'LIQ-REF'] : ['FLX-HYD', 'FLT-HYD', 'HH-46', 'BAT-12V', 'DENT-GD', 'FLT-AIR', 'PNE-2925'];
            $w['parts'] = [];
            foreach ((array) array_rand(array_flip($candidates), mt_rand(1, 2)) as $refCode) {
                $qty = match ($refCode) { 'HH-46' => between(20, 60), 'LIQ-REF' => between(10, 25), 'DENT-GD' => 5, 'PNE-1200' => 2, default => 1 };
                $w['parts'][$refCode] = $qty;
            }
            $w['stage'] = 'parts';
            $w['next'] = strtotime("$date 14:00");
            $w['tries'] = 0;
        }
        if ($w['stage'] === 'parts' && $w['next'] <= strtotime("$date 23:59")) {
            at(date('Y-m-d H:i:s', max($w['next'], SeedClock::$now)));
            $missing = false;
            foreach ($w['parts'] as $refCode => $qty) {
                $done = attempt('OT pièce', static fn () => $orders->consumePart($wo, ['part_id' => $part[$refCode], 'quantity' => (string) $qty]) ?? true);
                if ($done === null) {
                    $missing = true;
                    // Rupture : l'OT passe en attente de pièce et un EDB urgent est émis.
                    $key = $code . '|' . $part[$refCode];
                    if (!isset($reorderOpen[$key])) {
                        $reorderOpen[$key] = $newEdb($code, $site[$code]['storekeeper'], $partInfo[$part[$refCode]]['type'] === 'tyre' ? 'tyres' : 'parts', 'urgent',
                            'Rupture : ' . $partInfo[$part[$refCode]]['name'], 'Pièce manquante pour un OT en cours sur ' . $e['code'],
                            [['part_id' => $part[$refCode], 'quantity' => (string) max($qty, is_array($partInfo[$part[$refCode]]['reorder']) ? 1 : $partInfo[$part[$refCode]]['reorder']), 'est_unit_cost' => (string) $partInfo[$part[$refCode]]['price']]],
                            'purchase', ['equipment_id' => $w['eq']]);
                    }
                } else {
                    unset($w['parts'][$refCode]);
                }
            }
            if ($missing) {
                attempt('OT attente pièce', static fn () => $orders->transition($wo, ['status' => 'waiting_part']));
                $w['next'] = strtotime("$date +2 days 09:00");
                $w['tries']++;
                if ($w['tries'] > 12) {
                    $w['parts'] = []; // abandon de la pièce, réparation de fortune
                }
                continue;
            }
            if ((string) $db->fetchValue('SELECT status FROM work_orders WHERE id = :id', ['id' => $wo]) === 'waiting_part') {
                attempt('OT reprise', static fn () => $orders->transition($wo, ['status' => 'in_progress']));
            }
            if (chance(0.22)) {
                attempt('OT prestation', static fn () => $orders->addService($wo, ['description' => pick(['Rechapage et équilibrage', 'Réparation de vérin en atelier externe', 'Diagnostic électronique concessionnaire', 'Soudure du godet']),
                    'service_date' => $date, 'supplier_id' => $suppliers['services'][0], 'amount' => chance(0.15) ? null : (string) between(150, 1800), 'document_ref' => 'FAC-GAK-' . mt_rand(100, 999)]));
            }
            $w['stage'] = 'finish';
            $w['next'] = strtotime("$date +" . mt_rand(0, 2) . ' days 15:30');
            continue;
        }
        if ($w['stage'] === 'finish') {
            $meterValue = (int) round($e['meter']);
            attempt('OT fin', static fn () => $orders->transition($wo, ['status' => 'done', 'resolution' => 'Réparation effectuée, essais concluants. ' . ($w['title'] ?? ''),
                'failure_cause' => pick(['wear', 'wear', 'component_failure', 'misuse', 'maintenance_lapse']), $e['km'] ? 'odometer_km' : 'hour_meter' => (string) $meterValue]));
            $w['stage'] = 'release';
            $w['next'] = strtotime("$date +1 day 08:30");
            tally('OT terminés');
            continue;
        }
        if ($w['stage'] === 'release') {
            $release = (int) $db->fetchValue("SELECT id FROM equipment_releases WHERE equipment_id = :e AND status = 'pending' ORDER BY id DESC LIMIT 1", ['e' => $w['eq']]);
            if ($release > 0) {
                // Une remise en service sur dix est refusée par le supérieur : l'engin repart en réparation.
                if (chance(0.1)) {
                    decide($site[$code]['supervisor'], 'equipment_release', $release, 'unfavorable', 'Essai non concluant : fuite résiduelle constatée.');
                    $retry = (int) attempt('OT reprise', static fn () => svc($tech, WorkOrderService::class)->create(['equipment_id' => $w['eq'], 'title' => 'Reprise : ' . ($w['title'] ?? 'intervention'), 'priority' => 'high', 'immobilizing' => '1']));
                    if ($retry > 0) {
                        $openWo[$retry] = ['eq' => $w['eq'], 'stage' => 'open', 'next' => strtotime("$date +1 day 08:00"), 'type' => 'corrective', 'title' => 'Reprise'];
                    }
                    tally('remises en service refusées');
                } else {
                    decide($site[$code]['manager'], 'equipment_release', $release);
                    tally('remises en service validées');
                }
            }
            unset($openWo[$wo]);
        }
    }
    unset($w);

    // --- Réapprovisionnement : seuil mini atteint → EDB du magasinier -------------------------------------------------
    if ($workday) {
        at("$date 10:00:00");
        foreach ($db->fetchAll('SELECT ps.part_id, ps.site_id, ps.quantity, ps.min_quantity FROM part_stocks ps WHERE ps.min_quantity > 0 AND ps.quantity < ps.min_quantity') as $low) {
            $code = array_search((int) $low['site_id'], $siteId, true);
            $key = $code . '|' . $low['part_id'];
            if (isset($reorderOpen[$key]) || $code === false) {
                continue;
            }
            $info = $partInfo[(int) $low['part_id']];
            $qty = is_array($info['reorder']) ? $info['reorder'][$code] : $info['reorder'];
            $category = ['fuel' => 'fuel', 'tyre' => 'tyres', 'lubricant' => 'consumables', 'consumable' => 'consumables'][$info['type']] ?? 'parts';
            $reorderOpen[$key] = $newEdb($code, $site[$code]['storekeeper'], $category, $info['type'] === 'fuel' ? 'urgent' : 'normal', 'Réapprovisionnement ' . $info['name'],
                'Stock sous le seuil minimum (' . (float) $low['quantity'] . ' / ' . (float) $low['min_quantity'] . ').',
                [['part_id' => $low['part_id'], 'quantity' => (string) $qty, 'est_unit_cost' => (string) $info['price']]], 'purchase');
        }
        // Besoins de consommables des services, servis depuis le stock.
        if (chance(0.18)) {
            $code = pick($operating);
            $newEdb($code, $site[$code]['requester'], 'consumables', 'normal', 'Consommables atelier', 'Nettoyage et entretien courant de l\'atelier.',
                [['part_id' => $part['CHIF'], 'quantity' => (string) mt_rand(5, 15), 'est_unit_cost' => '2.1'], ['part_id' => $part['GRAISSE'], 'quantity' => (string) mt_rand(2, 6), 'est_unit_cost' => '6.5']], 'stock');
        }
        // Prestation de service (gardiennage, location ponctuelle) achetée sans stock.
        if (chance(0.05)) {
            $code = pick($operating);
            $newEdb($code, $site[$code]['requester'], 'services', 'normal', pick(['Location grue 50 t (2 jours)', 'Analyse d\'huile laboratoire', 'Étalonnage des débitmètres de cuve']),
                'Besoin ponctuel du chantier.', [['description' => 'Prestation', 'quantity' => '1', 'unit' => 'forfait', 'est_unit_cost' => (string) between(400, 2600)]], 'purchase');
        }
    }

    // --- États de besoins : circuit, service depuis le stock ou achat, réception, facture, paiement -----------------------
    foreach ($edbs as $id => &$b) {
        if ($b['next'] > strtotime("$date 23:59") || !$workday) {
            continue;
        }
        $code = $b['site'];
        at("$date " . sprintf('%02d:%02d:00', mt_rand(9, 15), mt_rand(0, 59)));
        $status = (string) $db->fetchValue('SELECT status FROM purchase_requests WHERE id = :id', ['id' => $id]);
        if ($b['stage'] === 'approval') {
            $step = pendingStep('purchase_request', $id);
            if ($step !== null) {
                $actor = ['visa' => $site[$code]['supervisor'], 'opinion' => $site[$code]['supervisor'], 'logistics' => $u['logistics'], 'authorization' => $u['logistics'], 'finance' => $u['finance']][$step] ?? $u['logistics'];
                // Quelques refus motivés de la Direction Logistique sur les achats non urgents.
                if ($step === 'logistics' && $b['category'] === 'services' && chance(0.25)) {
                    decide($actor, 'purchase_request', $id, 'unfavorable', 'Prestation à reporter : budget du trimestre atteint.');
                } else {
                    decide($actor, 'purchase_request', $id);
                }
                $b['next'] = SeedClock::$now + 86400 * (chance(0.6) ? 0 : 1);
                continue;
            }
            $status = (string) $db->fetchValue('SELECT status FROM purchase_requests WHERE id = :id', ['id' => $id]);
            if ($status !== 'approved') {
                foreach ($reorderOpen as $key => $edbId) {
                    if ($edbId === $id) {
                        unset($reorderOpen[$key]);
                    }
                }
                unset($edbs[$id]);
                continue;
            }
            $b['stage'] = $b['path'];
            $b['next'] = SeedClock::$now + 86400 * mt_rand(0, 1);
            continue;
        }
        $lines = $db->fetchAll('SELECT * FROM purchase_request_lines WHERE request_id = :r ORDER BY id', ['r' => $id]);
        if ($b['stage'] === 'stock') {
            foreach ($lines as $line) {
                attempt('EDB service stock', static fn () => svc($site[$code]['storekeeper'], PurchaseRequestService::class)->issueFromStock($id, ['line_id' => $line['id'], 'quantity' => (string) (float) $line['qty_requested']]));
            }
            tally('EDB servis depuis le stock');
            unset($edbs[$id]);
            continue;
        }
        if ($b['stage'] === 'purchase') {
            $buyer = svc($u['buyer'], PurchaseOrderService::class);
            $first = $lines[0];
            $info = $first['part_id'] !== null ? $partInfo[(int) $first['part_id']] : ['type' => 'service', 'price' => (float) $first['est_unit_cost']];
            $kind = ['fuel' => 'fuel', 'tyre' => 'tyres', 'lubricant' => 'lubricants'][$info['type']] ?? ($info['type'] === 'service' ? 'services' : 'parts');
            $pool = $suppliers[$kind] ?? $suppliers['parts'];
            $total = 0.0;
            $prices = [];
            foreach ($lines as $line) {
                $unitPrice = round((float) ($line['est_unit_cost'] ?: 1) * between(0.92, 1.1, 3), 2);
                $prices[(int) $line['id']] = $unitPrice;
                $total += $unitPrice * (float) $line['qty_requested'];
            }
            $quotes = [];
            foreach (array_slice($pool, 0, $total > 5000 && count($pool) > 1 ? 2 : 1) as $i => $supplier) {
                $quotes[] = attempt('devis', static fn () => $buyer->addQuote($id, ['supplier_id' => $supplier, 'reference' => 'DV-' . mt_rand(1000, 9999), 'amount' => (string) round($total * (1 + $i * 0.06), 2), 'delivery_days' => (string) mt_rand(2, 6)]));
            }
            if ($quotes[0] === null) {
                unset($edbs[$id]);
                continue;
            }
            attempt('choix du devis', static fn () => $buyer->selectQuote($id, ['quote_id' => $quotes[0]]));
            $orderLines = [];
            foreach ($lines as $line) {
                $orderLines[$line['id']] = ['quantity' => (string) (float) $line['qty_requested'], 'unit_price' => (string) $prices[(int) $line['id']]];
            }
            $order = (int) attempt('commande', static fn () => $buyer->createOrder($id, ['expected_on' => date('Y-m-d', strtotime("$date +4 days")), 'lines' => $orderLines]));
            if ($order === 0) {
                unset($edbs[$id]);
                continue;
            }
            tally('commandes');
            $b['stage'] = 'receipt';
            $b['order'] = $order;
            $b['next'] = SeedClock::$now + 86400 * mt_rand(2, 6);
            continue;
        }
        if ($b['stage'] === 'receipt') {
            $accepted = [];
            $rejected = [];
            $reasons = [];
            foreach ($db->fetchAll('SELECT id, qty_ordered AS quantity FROM purchase_order_lines WHERE order_id = :o', ['o' => $b['order']]) as $line) {
                $qty = (float) $line['quantity'];
                if ($qty >= 4 && chance(0.08)) {
                    $rejected[$line['id']] = '1';
                    $reasons[$line['id']] = 'Article endommagé à la livraison';
                    $qty -= 1;
                }
                $accepted[$line['id']] = (string) $qty;
            }
            attempt('réception', static fn () => svc($site[$code]['storekeeper'], PurchaseOrderService::class)->receive($b['order'], ['delivery_note' => 'BL-' . mt_rand(10000, 99999),
                'carrier_id' => chance(0.5) ? $carrier : null, 'vehicle_plate' => mt_rand(1000, 9999) . 'AA05', 'driver_name' => pick(['Mwamba', 'Kalonji', 'Tshimanga']),
                'accepted' => $accepted, 'rejected' => $rejected, 'reject_reason' => $reasons]));
            tally('réceptions');
            foreach ($reorderOpen as $key => $edbId) {
                if ($edbId === $id) {
                    unset($reorderOpen[$key]);
                }
            }
            $b['stage'] = 'invoice';
            $b['next'] = SeedClock::$now + 86400 * mt_rand(1, 4);
            continue;
        }
        if ($b['stage'] === 'invoice') {
            $amount = (float) $db->fetchValue('SELECT total_amount FROM purchase_orders WHERE id = :o', ['o' => $b['order']]);
            $invoice = (int) attempt('facture', static fn () => svc($u['finance'], PaymentService::class)->recordInvoice(['order_id' => $b['order'], 'invoice_number' => 'F' . date('Y', SeedClock::$now) . '-' . mt_rand(10000, 99999),
                'invoice_date' => $date, 'amount' => (string) $amount], chance(0.7) ? proof('facture.pdf') : null));
            if ($invoice > 0) {
                $pay = (int) attempt('demande de décaissement', static fn () => svc($u['buyer'], PaymentService::class)->request(['kind' => 'invoice', 'invoice_id' => $invoice, 'amount' => (string) $amount, 'reason' => 'Règlement de la facture fournisseur']));
                if ($pay > 0) {
                    $payments[$pay] = ['site' => $code, 'next' => SeedClock::$now + 86400, 'cash' => false];
                }
                tally('factures');
            }
            unset($edbs[$id]);
        }
    }
    unset($b);

    // --- Dépenses de caisse : missions et petits achats --------------------------------------------------------------
    if ($workday && chance(0.35)) {
        $code = pick(['KOL', 'LIK', 'KAM', 'LUB']);
        at("$date 11:20:00");
        $pay = (int) attempt('dépense', static fn () => svc($site[$code]['requester'] ?? $site['KOL']['requester'], PaymentService::class)->request(['kind' => 'expense', 'site_id' => $siteId[$code],
            'beneficiary' => pick(['Hôtel Bon Séjour', 'Transport Kalumba', 'Quincaillerie du Katanga', 'Restaurant La Paillote', 'Agent de mission']),
            'category' => pick(['other', 'materials', 'consumables']), 'amount' => (string) between(40, 420), 'reason' => pick(['Frais de mission', 'Achat urgent de visserie', 'Transport du personnel', 'Repas d\'équipe de nuit'])]));
        if ($pay > 0) {
            $payments[$pay] = ['site' => $code, 'next' => SeedClock::$now + 86400 * mt_rand(0, 1), 'cash' => true];
        }
    }
    foreach ($payments as $id => &$p) {
        if ($p['next'] > strtotime("$date 23:59") || !$workday) {
            continue;
        }
        at("$date " . sprintf('%02d:%02d:00', mt_rand(12, 16), mt_rand(0, 59)));
        $step = pendingStep('payment_request', $id);
        if ($step !== null) {
            decide($step === 'verification' ? $u['finance'] : $u['director'], 'payment_request', $id);
            $p['next'] = SeedClock::$now + 86400 * mt_rand(0, 1);
            continue;
        }
        $status = (string) $db->fetchValue('SELECT status FROM payment_requests WHERE id = :id', ['id' => $id]);
        if ($status === 'approved') {
            $code = $p['site'];
            $cashier = $site[$code]['cashier'];
            if ($p['cash']) {
                attempt('paiement caisse', static fn () => svc($cashier, PaymentService::class)->pay($id, ['payment_method_id' => $method['CAISSE'], 'cash_register_id' => $register[$code]], proof('recu.pdf')));
            } else {
                $ref = 'VIR-' . date('Y', SeedClock::$now) . '-' . (++$payRef);
                attempt('paiement virement', static fn () => svc($cashier, PaymentService::class)->pay($id, ['payment_method_id' => $method['VIREMENT'], 'payment_reference' => $ref], proof('ordre-virement.pdf')));
            }
            tally('paiements');
        }
        unset($payments[$id]);
    }
    unset($p);

    // --- Caisse : approvisionnement mensuel, arrêté du soir (jusqu'à fin octobre) ---------------------------------------
    if (substr($date, 8, 2) === '03') {
        at("$date 08:00:00");
        foreach ($register as $code => $reg) {
            attempt('caisse', static fn () => svc($site[$code]['cashier'], CashService::class)->manualEntry($reg, ['direction' => 'in', 'amount' => '3000', 'label' => 'Approvisionnement de caisse depuis la banque']));
        }
    }
    if ($date <= '2026-10-31') {
        at("$date 18:00:00");
        foreach ($register as $code => $reg) {
            $finance = svc($site[$code]['cashier'], \App\Repositories\FinanceRepository::class);
            if (!(bool) $db->fetchValue('SELECT 1 FROM cash_entries WHERE register_id = :r AND entry_date = :d', ['r' => $reg, 'd' => $date]) || $finance->isDayClosed($reg, $date)) {
                continue;
            }
            $closing = (float) $finance->journal($reg, $date)['closing'];
            $gap = chance(0.04) ? -between(1, 15) : 0.0;
            attempt('arrêté de caisse', static fn () => svc($site[$code]['cashier'], CashService::class)->close($reg, ['closing_date' => $date, 'counted_balance' => (string) round($closing + $gap, 2),
                'gap_note' => $gap !== 0.0 ? 'Erreur de rendu de monnaie' : null]));
            tally('arrêtés de caisse');
        }
    }

    // --- Transferts de stock : le 10 du mois, filtres de Kolwezi vers Kambove ------------------------------------------
    if (substr($date, 8, 2) === '10') {
        at("$date 09:00:00");
        $tid = (int) attempt('transfert de stock', static fn () => svc($site['KAM']['storekeeper'], StockTransferService::class)->request(['from_site_id' => $siteId['KOL'], 'to_site_id' => $siteId['KAM'],
            'reason' => 'Dotation mensuelle en filtres de la base transport', 'carrier_id' => $carrier,
            'lines' => [['part_id' => $part['FLT-HUI'], 'quantity' => '4'], ['part_id' => $part['FLT-AIR'], 'quantity' => '2']]]));
        if ($tid > 0) {
            $transfers[$tid] = ['stage' => 'approve', 'next' => strtotime("$date 14:00")];
            tally('transferts de stock');
        }
    }
    foreach ($transfers as $tid => &$t) {
        if ($t['next'] > strtotime("$date 23:59")) {
            continue;
        }
        at(date('Y-m-d H:i:s', max($t['next'], strtotime("$date 08:00"))));
        if ($t['stage'] === 'approve') {
            decide($u['logistics'], 'stock_transfer', $tid);
            $t['stage'] = 'ship';
            $t['next'] = strtotime("$date +1 day 10:00");
        } elseif ($t['stage'] === 'ship') {
            $shipped = array_column($db->fetchAll('SELECT part_id, qty_requested AS quantity FROM stock_transfer_lines WHERE transfer_id = :t', ['t' => $tid]), 'quantity', 'part_id');
            attempt('expédition', static fn () => svc($site['KOL']['storekeeper'], StockTransferService::class)->ship($tid, ['shipped' => array_map('strval', $shipped)]));
            $t['stage'] = 'receive';
            $t['next'] = strtotime("$date +1 day 15:00");
            $t['qty'] = $shipped;
        } else {
            // Novembre : le transfert reste en transit (à réceptionner pour tester l'écran).
            if ($month === '2026-11') {
                unset($transfers[$tid]);
                continue;
            }
            attempt('réception transfert', static fn () => svc($site['KAM']['storekeeper'], StockTransferService::class)->receive($tid, ['received' => array_map('strval', $t['qty'])]));
            unset($transfers[$tid]);
        }
    }
    unset($t);

    // --- Transferts d'engins entre sites -------------------------------------------------------------------------------
    if (isset($fleetPlan[$date]) && isset($byCode[$fleetPlan[$date][0]])) {
        [$codeEq, $to] = $fleetPlan[$date];
        $eid = $byCode[$codeEq];
        $from = $eq[$eid]['site'];
        at("$date 08:30:00");
        $fid = (int) attempt('transfert d\'engin', static fn () => svc($site[$from]['manager'], FleetTransferService::class)->request(['equipment_id' => $eid, 'to_site_id' => $siteId[$to],
            'reason' => 'Renfort du chantier de ' . $sites[$to][2], 'transport_mode' => 'low_loader', 'carrier_id' => $carrier, 'tractor_plate' => '7712AB05', 'trailer_plate' => 'REM-0451',
            'transport_conditions' => 'Porte-char surbaissé, engin arrimé, godet posé.', 'driver_name' => 'Kabila J.', 'driver_phone' => '+243 970 000 001',
            'planned_departure' => date('Y-m-d\TH:i', strtotime("$date +2 days 07:00")), 'planned_arrival' => date('Y-m-d\TH:i', strtotime("$date +2 days 17:00"))]));
        if ($fid > 0) {
            $fleetMoves[$fid] = ['eq' => $eid, 'from' => $from, 'to' => $to, 'stage' => 'opinion', 'next' => strtotime("$date 11:00")];
            tally('transferts d\'engins');
        }
    }
    foreach ($fleetMoves as $fid => &$f) {
        if ($f['next'] > strtotime("$date 23:59")) {
            continue;
        }
        at(date('Y-m-d H:i:s', max($f['next'], strtotime("$date 08:00"))));
        $e = $eq[$f['eq']];
        $results = array_fill_keys(FleetTransferService::ITEMS, 'ok');
        $results['tyres'] = 'reserve';
        $meterField = $e['km'] ? 'odometer_km' : 'hour_meter';
        if ($f['stage'] === 'opinion') {
            decide($site[$f['from']]['supervisor'], 'fleet_transfer', $fid);
            attempt('check-list', static fn () => svc($site[$f['from']]['technician'], FleetTransferService::class)->recordCheck($fid, ['results' => $results, 'notes' => ['tyres' => 'Usure à surveiller'],
                'conclusion' => 'conditional', 'remarks' => 'Apte au transfert, usure des pneus à surveiller.', $meterField => (string) (int) round($e['meter'])]));
            decide($u['logistics'], 'fleet_transfer', $fid);
            $f['stage'] = 'depart';
            $f['next'] = strtotime("$date +2 days 07:00");
        } elseif ($f['stage'] === 'depart') {
            $meter = (int) round($e['meter']);
            $departed = attempt('départ engin', static fn () => svc($site[$f['from']]['manager'], FleetTransferService::class)->depart($fid, [$e['km'] ? 'departure_km' : 'departure_hours' => (string) $meter,
                'departure_fuel' => '60', 'handover_to' => 'Kabila J. (Transports du Katanga)', 'driver_name' => 'Kabila J.', 'observations' => 'Départ conforme à l\'ordre de transfert.']));
            if ($departed === null && (string) $db->fetchValue('SELECT status FROM fleet_transfers WHERE id = :id', ['id' => $fid]) !== 'departed') {
                unset($fleetMoves[$fid]);
                continue;
            }
            $eq[$f['eq']]['away'] = true;
            $f['stage'] = 'receive';
            $f['next'] = strtotime("$date +1 day 16:00");
        } else {
            if ($date >= '2026-11-16') {
                unset($fleetMoves[$fid]); // dernier transfert laissé « en route »
                continue;
            }
            $meter = (int) round($e['meter']) + 1;
            attempt('réception engin', static fn () => svc($site[$f['to']]['manager'], FleetTransferService::class)->receive($fid, ['results' => $results, 'notes' => ['tyres' => 'Usure des pneus avant'], 'conclusion' => 'conditional', 'remarks' => 'Pneus usés, à remplacer au prochain entretien.',
                $e['km'] ? 'arrival_km' : 'arrival_hours' => (string) $meter, 'arrival_fuel' => '55', 'arrival_note' => 'Engin reçu en bon état, rayure carrosserie déjà connue.']));
            if ((string) $db->fetchValue('SELECT status FROM fleet_transfers WHERE id = :id', ['id' => $fid]) !== 'received') {
                unset($fleetMoves[$fid]);
                continue;
            }
            $eq[$f['eq']]['site'] = $f['to'];
            $eq[$f['eq']]['meter'] = $meter;
            $eq[$f['eq']]['away'] = false;
            unset($fleetMoves[$fid]);
        }
    }
    unset($f);

    // --- Fin de trimestre : inventaires --------------------------------------------------------------------------------
    if (in_array($date, ['2026-03-31', '2026-06-30', '2026-09-30'], true)) {
        foreach ($operating as $code) {
            at("$date 17:00:00");
            $keeper = svc($site[$code]['storekeeper'], StockInventoryService::class);
            $inv = (int) attempt('inventaire', static fn () => $keeper->open(['site_id' => $siteId[$code], 'counted_on' => $date, 'note' => 'Inventaire trimestriel']));
            if ($inv === 0) {
                continue;
            }
            $counted = [];
            $why = [];
            foreach ($db->fetchAll('SELECT part_id, quantity FROM part_stocks WHERE site_id = :s AND quantity > 0', ['s' => $siteId[$code]]) as $row) {
                $qty = (float) $row['quantity'];
                if (chance(0.15) && (int) $row['part_id'] !== $part['GO-001']) {
                    $qty = max(0, $qty - mt_rand(1, 2));
                    $why[$row['part_id']] = pick(['Article cassé non déclaré', 'Erreur de sortie non saisie', 'Écart de comptage du précédent inventaire']);
                }
                $counted[$row['part_id']] = (string) $qty;
            }
            attempt('comptage', static fn () => $keeper->saveCounts($inv, ['counted' => $counted, 'justification' => $why]));
            $result = attempt('inventaire soumis', static fn () => $keeper->submit($inv));
            at("$date 18:30:00");
            if ($result === 'submitted') {
                decide($u['logistics'], 'stock_inventory', $inv);
            }
            tally('inventaires');
        }
    }

    // --- Clôtures du mois précédent (le 5) : stock puis coûts, de janvier à septembre ----------------------------------
    if (substr($date, 8, 2) === '05') {
        $period = date('Y-m', strtotime("$date -1 month"));
        if ($period <= '2026-09') {
            at("$date 10:00:00");
            foreach (['KOL', 'LIK', 'KAM', 'LUB'] as $code) {
                attempt('clôture stock', static fn () => svc($u['finance'], StockPeriodService::class)->close(['site_id' => $siteId[$code], 'period' => $period]));
                attempt('clôture coûts', static fn () => svc($u['finance'], EquipmentCostService::class)->close(['site_id' => $siteId[$code], 'period' => $period, 'note' => 'Rapport mensuel validé.']));
            }
            tally('mois clôturés');
        }
    }

    // --- Charges mensuelles des engins loués ----------------------------------------------------------------------------
    if (substr($date, 8, 2) === '28') {
        at("$date 09:00:00");
        foreach ($eq as $id => $e) {
            if ($e['rental']) {
                attempt('charge', static fn () => svc($u['finance'], EquipmentCostService::class)->addCharge(['equipment_id' => $id, 'charge_date' => $date, 'label' => 'Location mensuelle (contrat ' . 'LOC-2026-' . substr($e['code'], -2) . ')',
                    'amount' => '6500', 'document_ref' => 'FAC-KEL-' . substr($date, 5, 2)]));
            }
        }
    }

    // --- Événements ponctuels du lot 7 ------------------------------------------------------------------------------------
    if ($date === '2026-09-15' && isset($byCode['LIK-CMP-01'])) {
        at("$date 09:00:00");
        attempt('hors service', static fn () => svc($site['LIK']['manager'], EquipmentService::class)->changeStatus($byCode['LIK-CMP-01'], ['status' => 'out_of_service', 'reason' => 'Fissure du châssis : réforme proposée à la direction']));
    }
    if ($date === '2026-11-10') {
        at("$date 10:00:00");
        // Assurance d'un bus expirée : renouvellement en cours, avec responsable.
        $bus = $byCode['KAM-BUS-01'] ?? 0;
        $doc = (int) $db->fetchValue('SELECT id FROM equipment_documents WHERE equipment_id = :e AND document_type_id = :t ORDER BY id DESC LIMIT 1', ['e' => $bus, 't' => $types['insurance']]);
        if ($doc > 0) {
            $db->execute('UPDATE equipment_documents SET expires_at = :d WHERE id = :id', ['d' => '2026-11-05', 'id' => $doc]);
            attempt('renouvellement', static fn () => svc($site['KAM']['manager'], EquipmentDocumentService::class)->setRenewal($bus, $doc, ['renewing' => '1', 'responsible_id' => $u['logistics'], 'renewal_note' => 'Dossier déposé chez SONAS, attestation attendue']));
        }
    }

    $day = strtotime('+1 day', $day);
}

// =====================================================================================================================
// 3. Plans préventifs (au jour réel) et remise de l'horloge
// =====================================================================================================================
$db->pdo()->exec('SET timestamp = DEFAULT');
SeedClock::$now = \time();
foreach ($eq as $id => $e) {
    if ($e['site'] === 'LUB') {
        continue;
    }
    $current = (int) round($e['meter']);
    $done = $current - (int) round($e['since_service']);
    attempt('plan préventif', static fn () => svc($site[$e['site']]['manager'], PreventiveService::class)->create($e['km']
        ? ['equipment_id' => $id, 'is_active' => '1', 'title' => 'Entretien 10 000 km', 'checklist' => 'Vidanger l\'huile moteur
Remplacer le filtre à huile
Remplacer le filtre à gasoil
Contrôler les plaquettes et disques de frein
Contrôler la pression et l\'usure des pneumatiques
Contrôler l\'éclairage et la signalisation
Contrôler la batterie et les courroies
Relever le kilométrage', 'interval_km' => '10000', 'interval_days' => '180', 'last_done_at' => date('Y-m-d', strtotime('-' . mt_rand(10, 120) . ' days')), 'last_done_km' => (string) $done]
        : ['equipment_id' => $id, 'is_active' => '1', 'title' => 'Entretien 250 h', 'checklist' => 'Vidanger l\'huile moteur (15W40)
Remplacer le filtre à huile
Remplacer le filtre à gasoil
Contrôler et nettoyer le filtre à air
Contrôler les niveaux (liquide de refroidissement, hydraulique)
Graisser les articulations et axes
Contrôler les flexibles hydrauliques (fuites, usure)
Contrôler l\'état des pneumatiques ou du train de roulement
Relever le compteur horaire', 'interval_hours' => '250', 'interval_days' => '90', 'last_done_at' => date('Y-m-d', strtotime('-' . mt_rand(5, 60) . ' days')), 'last_done_hours' => (string) $done]));
}
@unlink($proofPath);

// =====================================================================================================================
// 4. Compte rendu
// =====================================================================================================================
echo "\nDonnées générées :\n";
foreach ($stats as $key => $n) {
    echo sprintf("  %-32s %d\n", $key, $n);
}
foreach (['purchase_requests' => 'EDB en base', 'stock_movements' => 'mouvements de stock', 'fuel_refills' => 'ravitaillements', 'work_orders' => 'ordres de travail',
    'approval_actions' => 'actes de validation', 'payment_requests' => 'demandes de décaissement', 'cash_entries' => 'écritures de caisse', 'activity_log' => 'lignes du journal'] as $table => $label) {
    echo sprintf("  %-32s %d\n", $label, (int) $db->fetchValue("SELECT COUNT(*) FROM $table"));
}
if ($errors !== []) {
    arsort($errors);
    echo "\nActions refusées par les règles métier (" . array_sum($errors) . ") :\n";
    foreach (array_slice($errors, 0, 40, true) as $message => $n) {
        echo sprintf("  %5d × %s\n", $n, mb_substr($message, 0, 220));
    }
}
echo "\nComptes de démonstration (mot de passe : " . PASSWORD . ") :\n";
foreach ($people as [$email, $role, $scope]) {
    echo sprintf("  %-34s %-12s %s\n", $email, $role, $scope);
}
}
