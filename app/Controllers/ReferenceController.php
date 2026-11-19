<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\ReferenceCatalog;
use App\Repositories\ReferenceRepository;
use App\Repositories\SiteRepository;
use App\Services\ReferenceImportService;
use App\Services\ReferenceService;

/** Écran générique des référentiels communs : projets, magasins, départements, centres de coûts, fournisseurs… */
final class ReferenceController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly ReferenceRepository $references,
        private readonly ReferenceService $service,
        private readonly SiteRepository $sites,
        private readonly ReferenceImportService $importer,
    ) {
        parent::__construct($ctx);
    }

    public function home(Request $request): Response
    {
        return $this->redirect('/references/' . ReferenceCatalog::types()[0]);
    }

    public function index(Request $request, array $params): Response
    {
        $type = $this->type($params);
        $search = mb_substr((string) $request->string('q'), 0, 100);
        $siteId = $this->siteFilter($request);

        return $this->render('references/index', [
            'title' => t('nav.references'),
            'type' => $type,
            'def' => ReferenceCatalog::get($type),
            'list' => $this->references->paginateList($type, $search, $siteId, max(1, (int) $request->int('page'))),
            'search' => $search,
            'siteId' => $siteId,
            'sites' => $this->sites->listActive(),
        ]);
    }

    public function create(Request $request, array $params): Response
    {
        $type = $this->type($params);

        return $this->form($type, ['is_active' => 1, 'is_central' => 1, 'site_id' => $this->siteFilter($request)], null);
    }

    public function store(Request $request, array $params): Response
    {
        $type = $this->type($params);
        $this->service->create($type, $request->body);

        return $this->redirect("/references/$type", t('flash.created'));
    }

    public function edit(Request $request, array $params): Response
    {
        $type = $this->type($params);
        $item = $this->references->find($type, $this->id($params)) ?? throw HttpException::notFound();

        return $this->form($type, $item, (int) $item['id']);
    }

    public function update(Request $request, array $params): Response
    {
        $type = $this->type($params);
        $this->service->update($type, $this->id($params), $request->body);

        return $this->redirect("/references/$type", t('flash.updated'));
    }

    /** Import contrôlé d'un fichier Excel / CSV : dépôt, contrôle, confirmation, journal. */
    public function importForm(Request $request, array $params): Response
    {
        $type = $this->type($params);

        return $this->render('references/import', [
            'title' => t(ReferenceCatalog::labelKey($type)) . ' — ' . t('reference.import'),
            'type' => $type,
            'columns' => $this->importer->columns($type),
            'history' => $this->importer->history($type),
            'maxRows' => ReferenceImportService::MAX_ROWS,
        ]);
    }

    public function importPreview(Request $request, array $params): Response
    {
        $preview = $this->importer->preview($this->type($params), self::rows($request));
        $preview['lines'] = array_map(static fn (array $l): array => array_diff_key($l, ['data' => true]), $preview['lines']);

        return $this->json($preview);
    }

    public function importRun(Request $request, array $params): Response
    {
        $type = $this->type($params);
        $result = $this->importer->import($type, self::rows($request), $request->string('file_name'));

        return $this->json($result + ['message' => t('import.done', ['count' => $result['counts'][ReferenceImportService::NEW]])]);
    }

    /** @return list<array> */
    private static function rows(Request $request): array
    {
        $rows = $request->body['rows'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function form(string $type, array $item, ?int $id): Response
    {
        $def = ReferenceCatalog::get($type);
        $options = [];
        foreach ($def['fields'] as $name => $field) {
            if ($field[0] === 'site') {
                $options[$name] = array_column($this->sites->listActive(), 'name', 'id');
            } elseif ($field[0] === 'ref') {
                $options[$name] = $this->references->options($field[2]['ref']);
            } elseif ($field[0] === 'select') {
                $options[$name] = [];
                foreach ($field[2]['options'] as $value) {
                    $options[$name][$value] = t($field[2]['label'] . '.' . $value);
                }
            }
        }

        return $this->render('references/form', [
            'title' => t(ReferenceCatalog::labelKey($type)) . ' — ' . t($id === null ? 'reference.new' : 'reference.edit'),
            'type' => $type,
            'def' => $def,
            'item' => $item,
            'id' => $id,
            'options' => $options,
        ]);
    }

    private function type(array $params): string
    {
        $type = (string) ($params['type'] ?? '');
        if (!ReferenceCatalog::has($type)) {
            throw HttpException::notFound();
        }

        return $type;
    }
}
