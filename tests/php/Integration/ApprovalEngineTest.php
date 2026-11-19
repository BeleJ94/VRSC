<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Core\ValidationException;
use App\Services\ApprovalService;
use App\Services\AttachmentService;
use App\Services\DelegationService;
use App\Services\NumberingService;
use Tests\DatabaseTestCase;

/**
 * Moteurs transversaux : circuit d'approbation (séparation des fonctions, avis ≠ autorisation, dérogations,
 * délégations), numérotation et pièces jointes.
 */
final class ApprovalEngineTest extends DatabaseTestCase
{
    private int $site;
    private int $other;
    private int $requester;
    private int $supervisor;
    private int $technician;
    private int $logistics;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->createSite('KOLWEZI');
        $this->other = $this->createSite('LIKASI');
        $this->requester = $this->createUser('requester', [$this->site]);
        $this->supervisor = $this->createUser('supervisor', [$this->site]);
        $this->technician = $this->createUser('technician', [$this->site]);
        $this->logistics = $this->createUser('logistics', [], true);
        $this->admin = $this->createUser('admin');

        // Circuit type « transfert d'engin » : avis hiérarchique → contrôle technique → autorisation Logistique.
        $this->service($this->admin, ApprovalService::class)->defineFlow('test_transfer', 'Transfert (test)', [
            ['code' => 'opinion', 'name' => 'Avis hiérarchique', 'role' => 'supervisor', 'kind' => 'opinion', 'is_blocking' => false],
            ['code' => 'control', 'name' => 'Contrôle technique', 'role' => 'technician', 'kind' => 'control'],
            ['code' => 'authorization', 'name' => 'Autorisation Logistique', 'role' => 'logistics', 'kind' => 'authorization'],
        ]);
    }

    private function approvals(int $userId): ApprovalService
    {
        return $this->service($userId, ApprovalService::class);
    }

    private function start(?float $amount = null, string $flow = 'test_transfer'): int
    {
        return $this->approvals($this->requester)->start($flow, 'fleet_transfer', 501, $this->site, $amount, 'OTV-2026-00001')['id'];
    }

    public function testFullCircuitRecordsDistinctDatedActs(): void
    {
        $id = $this->start();
        self::assertSame('opinion', $this->approvals($this->requester)->detail($id)['step_code']);

        self::assertSame(['status' => 'pending', 'step' => 'opinion', 'next_step' => 'control'],
            $this->approvals($this->supervisor)->act($id, ['decision' => 'favorable']));
        self::assertSame('authorization', $this->approvals($this->technician)->act($id, ['decision' => 'favorable', 'comment' => 'Apte'])['next_step']);
        self::assertSame('approved', $this->approvals($this->logistics)->act($id, ['decision' => 'favorable'])['status']);

        $detail = $this->approvals($this->admin)->detail($id);
        self::assertSame('approved', $detail['status']);
        self::assertNotNull($detail['closed_at']);
        self::assertSame(['opinion', 'control', 'authorization'], array_column($detail['actions'], 'step_code'));
        self::assertSame(['supervisor', 'technician', 'logistics'], array_column($detail['actions'], 'acted_role'));
        self::assertSame([$this->supervisor, $this->technician, $this->logistics], array_map('intval', array_column($detail['actions'], 'acted_by')));
        foreach ($detail['actions'] as $action) {
            self::assertNotEmpty($action['acted_at']);
            self::assertNotEmpty($action['actor_name']);
        }
        self::assertSame(4, (int) $this->value("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'fleet_transfer' AND entity_id = 501"));

        $this->expectException(ValidationException::class);
        $this->approvals($this->logistics)->act($id, ['decision' => 'favorable']);
    }

    public function testRequesterNeverValidatesOwnRequestAndRoleIsRequiredOnTheSite(): void
    {
        // Le demandeur détient aussi la fonction d'avis : il ne peut pourtant pas l'exercer sur sa propre demande.
        $this->db->execute("INSERT INTO user_roles (user_id, role, site_id) VALUES (:u, 'supervisor', :s)", ['u' => $this->requester, 's' => $this->site]);
        $id = $this->start();
        try {
            $this->approvals($this->requester)->act($id, ['decision' => 'favorable']);
            self::fail('Séparation demandeur / approbateur');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('decision', $e->errors);
        }
        // Un technicien n'a pas le rôle de l'étape « avis ».
        try {
            $this->approvals($this->technician)->act($id, ['decision' => 'favorable']);
            self::fail('Rôle de l\'étape exigé');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        // Un responsable hiérarchique d'un autre site ne voit même pas la demande.
        $elsewhere = $this->createUser('supervisor', [$this->other]);
        try {
            $this->approvals($elsewhere)->act($id, ['decision' => 'favorable']);
            self::fail('Hors périmètre');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM approval_actions'));
    }

    public function testOpinionIsAdvisoryButControlBlocks(): void
    {
        $id = $this->start();
        try {
            $this->approvals($this->supervisor)->act($id, ['decision' => 'unfavorable']);
            self::fail('Un avis défavorable doit être motivé');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('comment', $e->errors);
        }
        // Avis défavorable : consigné, l'autorité décide ensuite (avis ≠ autorisation).
        $result = $this->approvals($this->supervisor)->act($id, ['decision' => 'unfavorable', 'comment' => 'Engin nécessaire au chantier']);
        self::assertSame(['pending', 'control'], [$result['status'], $result['next_step']]);

        // Contrôle technique « inapte » : étape bloquante, la demande est rejetée.
        $result = $this->approvals($this->technician)->act($id, ['decision' => 'unfavorable', 'comment' => 'Freinage défaillant']);
        self::assertSame('rejected', $result['status']);
        try {
            $this->approvals($this->logistics)->act($id, ['decision' => 'favorable']);
            self::fail('Aucune autorisation après un contrôle défavorable');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('decision', $e->errors);
        }
    }

    public function testSamePersonCannotPassTwoStepsWithoutMotivatedOverride(): void
    {
        // La Direction Logistique détient aussi la fonction d'avis sur le site.
        $this->db->execute("INSERT INTO user_roles (user_id, role, site_id) VALUES (:u, 'supervisor', :s)", ['u' => $this->logistics, 's' => $this->site]);
        $id = $this->start();
        $this->approvals($this->logistics)->act($id, ['decision' => 'favorable']);
        $this->approvals($this->technician)->act($id, ['decision' => 'favorable']);
        try {
            $this->approvals($this->logistics)->act($id, ['decision' => 'favorable']);
            self::fail('Avis et autorisation par la même personne : dérogation motivée exigée');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('override_reason', $e->errors);
        }
        $result = $this->approvals($this->logistics)->act($id, ['decision' => 'favorable', 'override_reason' => 'Responsable hiérarchique absent, urgence chantier']);
        self::assertSame('approved', $result['status']);
        $last = $this->row('SELECT * FROM approval_actions ORDER BY id DESC LIMIT 1');
        self::assertSame([1, 'Responsable hiérarchique absent, urgence chantier'], [(int) $last['is_override'], $last['override_reason']]);

        // Sans habilitation de dérogation, la même situation est simplement refusée.
        $this->db->execute("INSERT INTO user_roles (user_id, role, site_id) VALUES (:u, 'technician', :s)", ['u' => $this->supervisor, 's' => $this->site]);
        $second = $this->start();
        $this->approvals($this->supervisor)->act($second, ['decision' => 'favorable']);
        $this->expectException(ValidationException::class);
        $this->approvals($this->supervisor)->act($second, ['decision' => 'favorable', 'override_reason' => 'Je m\'autorise']);
    }

    public function testAdminActsOnlyByTracedOverride(): void
    {
        $id = $this->start();
        try {
            $this->approvals($this->admin)->act($id, ['decision' => 'favorable']);
            self::fail('L\'administrateur n\'a pas la fonction d\'avis');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('override_reason', $e->errors);
        }
        $this->approvals($this->admin)->act($id, ['decision' => 'favorable', 'override_reason' => 'Poste vacant']);
        $action = $this->row('SELECT * FROM approval_actions ORDER BY id DESC LIMIT 1');
        self::assertSame([1, 'admin'], [(int) $action['is_override'], $action['acted_role']]);
    }

    public function testAmountThresholdsSelectTheSteps(): void
    {
        $this->approvals($this->admin)->defineFlow('test_purchase', 'Achat (test)', [
            ['code' => 'visa', 'name' => 'Visa', 'role' => 'supervisor', 'kind' => 'opinion', 'min_amount' => 100],
            ['code' => 'dl', 'name' => 'Direction Logistique', 'role' => 'logistics', 'min_amount' => 5000],
        ]);
        $requests = $this->approvals($this->requester);
        self::assertSame('approved', $requests->start('test_purchase', 'edb', 1, $this->site, 50.0)['status'], 'Sous les seuils : aucune étape');

        $mid = $requests->start('test_purchase', 'edb', 2, $this->site, 800.0)['id'];
        self::assertSame('approved', $this->approvals($this->supervisor)->act($mid, ['decision' => 'favorable'])['status']);

        $big = $requests->start('test_purchase', 'edb', 3, $this->site, 12000.0)['id'];
        self::assertSame('dl', $this->approvals($this->supervisor)->act($big, ['decision' => 'favorable'])['next_step']);
        self::assertSame('approved', $this->approvals($this->logistics)->act($big, ['decision' => 'favorable'])['status']);

        self::assertSame($big, (int) $this->approvals($this->admin)->forEntity('edb', 3)['id']);
    }

    public function testInboxListsOnlyWhatTheUserMayHandle(): void
    {
        $id = $this->start();
        self::assertSame([$id], array_map('intval', array_column($this->approvals($this->supervisor)->inbox(), 'id')));
        self::assertSame([], $this->approvals($this->technician)->inbox(), 'Pas encore son étape');
        self::assertSame([], $this->approvals($this->requester)->inbox());

        $this->approvals($this->supervisor)->act($id, ['decision' => 'favorable']);
        self::assertSame([], $this->approvals($this->supervisor)->inbox());
        self::assertCount(1, $this->approvals($this->technician)->inbox());
    }

    public function testDelegationGrantsTheFunctionForItsPeriodAndIsTraced(): void
    {
        $deputy = $this->createUser('requester', [$this->site]);
        $delegations = $this->service($this->admin, DelegationService::class);
        $today = date('Y-m-d');
        $base = ['to_user_id' => $deputy, 'site_id' => $this->site, 'starts_on' => $today, 'ends_on' => date('Y-m-d', strtotime('+7 days')), 'reason' => 'Congé annuel'];

        try {
            $delegations->create($base + ['from_user_id' => $this->supervisor, 'role' => 'cashier']);
            self::fail('On ne délègue que ce que l\'on détient');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('role', $e->errors);
        }
        try {
            $delegations->create(['to_user_id' => $this->supervisor] + $base + ['from_user_id' => $this->supervisor, 'role' => 'supervisor']);
            self::fail('Délégation à soi-même');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('to_user_id', $e->errors);
        }
        try {
            $delegations->create(['reason' => ''] + $base + ['from_user_id' => $this->supervisor, 'role' => 'supervisor']);
            self::fail('Motif obligatoire');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('reason', $e->errors);
        }

        $id = $this->start();
        try {
            $this->approvals($deputy)->act($id, ['decision' => 'favorable']);
            self::fail('Sans délégation, pas de fonction d\'avis');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }

        $delegationId = $delegations->create($base + ['from_user_id' => $this->supervisor, 'role' => 'supervisor']);
        $this->approvals($deputy)->act($id, ['decision' => 'favorable']);
        $action = $this->row('SELECT * FROM approval_actions ORDER BY id DESC LIMIT 1');
        self::assertSame([$deputy, $this->supervisor, 'supervisor', 0], [(int) $action['acted_by'], (int) $action['delegated_from'], $action['acted_role'], (int) $action['is_override']]);

        // Révoquée : la fonction disparaît, la trace reste.
        $delegations->revoke($delegationId);
        $next = $this->start();
        try {
            $this->approvals($deputy)->act($next, ['decision' => 'favorable']);
            self::fail('Délégation révoquée');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        self::assertNotNull($this->value('SELECT revoked_at FROM delegations WHERE id = :id', ['id' => $delegationId]));

        // Délégation future ou échue : sans effet.
        $delegations->create(['starts_on' => date('Y-m-d', strtotime('+10 days')), 'ends_on' => date('Y-m-d', strtotime('+20 days'))] + $base
            + ['from_user_id' => $this->supervisor, 'role' => 'supervisor']);
        $this->expectException(HttpException::class);
        $this->approvals($deputy)->act($next, ['decision' => 'favorable']);
    }

    public function testCancellationIsMotivatedAndReservedToRequesterOrOverride(): void
    {
        $id = $this->start();
        try {
            $this->approvals($this->supervisor)->cancel($id, 'Je ne veux pas');
            self::fail('Annulation par un tiers');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        try {
            $this->approvals($this->requester)->cancel($id, '  ');
            self::fail('Annulation non motivée');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('comment', $e->errors);
        }
        $this->approvals($this->requester)->cancel($id, 'Besoin levé');
        self::assertSame('cancelled', $this->approvals($this->admin)->detail($id)['status']);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM approval_requests WHERE id = 0'), 'Rien n\'est supprimé');
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM approval_requests'));
    }

    public function testNumbersAreSequentialPerTypeAndYearAndNeverReused(): void
    {
        $numbering = $this->service($this->admin, NumberingService::class);
        self::assertSame('OTV-2026-00001', $numbering->next('OTV', 2026));
        self::assertSame('OTV-2026-00002', $numbering->next('OTV', 2026));
        self::assertSame('EDB-2026-00001', $numbering->next('EDB', 2026));
        self::assertSame('OTV-2027-00001', $numbering->next('OTV', 2027));
        self::assertSame('OTV-2026-00003', $numbering->next('OTV', 2026));
        self::assertMatchesRegularExpression('/^BS-\d{4}-00001$/', $numbering->next('BS'));

        $this->expectException(\InvalidArgumentException::class);
        $numbering->next('otv; DROP');
    }

    public function testAttachmentsAreVersionedCheckedAndScoped(): void
    {
        $dir = sys_get_temp_dir() . '/vrsc-att-' . bin2hex(random_bytes(4));
        $pdf = $dir . '-src.pdf';
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
        $upload = static fn (string $name): array => ['tmp_name' => $pdf, 'name' => $name, 'size' => filesize($pdf), 'error' => UPLOAD_ERR_OK];
        $attachments = $this->service($this->requester, AttachmentService::class);
        $attachments->useDirectory($dir);
        try {
            try {
                $attachments->assertPresent('payment', 9, 'proof');
                self::fail('Justificatif obligatoire');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('attachment', $e->errors);
            }
            $first = $attachments->add('payment', 9, $this->site, $upload('recu.pdf'), 'proof');
            $second = $attachments->add('payment', 9, $this->site, $upload('recu-corrige.pdf'), 'proof', 'Scan plus lisible', true);
            $attachments->assertPresent('payment', 9, 'proof');

            $current = $attachments->list('payment', 9, 'proof');
            self::assertSame([[$second, 2, 'recu-corrige.pdf']], array_map(static fn (array $a) => [(int) $a['id'], (int) $a['version'], $a['original_name']], $current));
            self::assertCount(2, $attachments->list('payment', 9, 'proof', true), 'L\'ancienne version reste consultable');
            self::assertSame('application/pdf', $attachments->file($first)['mime']);
            self::assertSame(64, strlen((string) $this->value('SELECT sha256 FROM attachments WHERE id = :id', ['id' => $second])));

            $php = $dir . '-evil.pdf';
            file_put_contents($php, '<?php echo 1;');
            try {
                $attachments->add('payment', 9, $this->site, ['tmp_name' => $php, 'name' => 'x.pdf', 'size' => 13, 'error' => UPLOAD_ERR_OK]);
                self::fail('Type de fichier contrôlé sur le contenu');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('attachment', $e->errors);
            } finally {
                @unlink($php);
            }

            $outsider = $this->service($this->createUser('requester', [$this->other]), AttachmentService::class);
            $outsider->useDirectory($dir);
            try {
                $outsider->file($first);
                self::fail('Pièce d\'un autre site');
            } catch (HttpException $e) {
                self::assertSame(404, $e->status);
            }
            $this->expectException(HttpException::class);
            $outsider->add('payment', 9, $this->site, $upload('intrus.pdf'));
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
            @unlink($pdf);
        }
    }
}
