<?php

declare(strict_types=1);

use App\Controllers\ActivityController;
use App\Controllers\ApprovalController;
use App\Controllers\PurchaseController;
use App\Controllers\StockController;
use App\Controllers\StockInventoryController;
use App\Controllers\StockTransferController;
use App\Controllers\AttachmentController;
use App\Controllers\DelegationController;
use App\Controllers\ReferenceController;
use App\Controllers\AuthController;
use App\Controllers\CatalogController;
use App\Controllers\DashboardController;
use App\Controllers\DocumentController;
use App\Controllers\EquipmentController;
use App\Controllers\EquipmentCostController;
use App\Controllers\FieldController;
use App\Controllers\FinanceController;
use App\Controllers\FleetTransferController;
use App\Controllers\ManagementController;
use App\Controllers\MeterController;
use App\Controllers\MeterEntryController;
use App\Controllers\MeterReadingController;
use App\Controllers\NotificationController;
use App\Controllers\PartController;
use App\Controllers\PreventiveController;
use App\Controllers\ProcurementController;
use App\Controllers\SearchController;
use App\Controllers\SettingsController;
use App\Controllers\SiteController;
use App\Controllers\SiteOverviewController;
use App\Controllers\UserController;
use App\Controllers\WorkOrderController;
use App\Controllers\WorkforceController;
use App\Core\Router;

/*
 * Toutes les routes exigent une session, sauf mention 'auth' => false.
 * Toute route POST est protégée par CSRF (App::dispatch).
 */
return static function (Router $r): void {
    $r->get('/login', [AuthController::class, 'showLogin'], ['auth' => false]);
    $r->post('/login', [AuthController::class, 'login'], ['auth' => false]);
    $r->post('/logout', [AuthController::class, 'logout']);
    $r->post('/locale', [AuthController::class, 'switchLocale'], ['auth' => false]);
    $r->post('/context/site', [AuthController::class, 'switchSite'], ['permission' => 'dashboard.view']);

    $r->get('/', [DashboardController::class, 'index'], ['permission' => 'dashboard.view']);
    $r->get('/dashboard/data', [DashboardController::class, 'data'], ['permission' => 'dashboard.view']);

    // Parc d'engins
    $r->get('/equipment', [EquipmentController::class, 'index'], ['permission' => 'equipment.view']);
    $r->get('/equipment/data', [EquipmentController::class, 'data'], ['permission' => 'equipment.view']);
    $r->get('/equipment/search', [EquipmentController::class, 'search'], ['permission' => 'equipment.view']);
    $r->get('/equipment/create', [EquipmentController::class, 'create'], ['permission' => 'equipment.manage']);
    $r->post('/equipment', [EquipmentController::class, 'store'], ['permission' => 'equipment.manage']);
    $r->get('/equipment/{id}', [EquipmentController::class, 'show'], ['permission' => 'equipment.view']);
    $r->get('/equipment/{id}/edit', [EquipmentController::class, 'edit'], ['permission' => 'equipment.manage']);
    $r->post('/equipment/{id}', [EquipmentController::class, 'update'], ['permission' => 'equipment.manage']);
    $r->post('/equipment/{id}/status', [EquipmentController::class, 'status'], ['permission' => 'equipment.status']);
    $r->post('/equipment/{id}/archive', [EquipmentController::class, 'archive'], ['permission' => 'equipment.manage']);
    $r->post('/equipment/{id}/readings', [EquipmentController::class, 'reading'], ['permission' => 'equipment.reading']);
    $r->post('/equipment/{id}/fluids', [EquipmentController::class, 'fluid'], ['permission' => 'equipment.reading']);
    $r->post('/equipment/{id}/documents', [EquipmentController::class, 'storeDocument'], ['permission' => 'documents.manage']);
    $r->post('/equipment/{id}/documents/{documentId}', [EquipmentController::class, 'updateDocument'], ['permission' => 'documents.manage']);
    $r->get('/equipment/{id}/documents/{documentId}/file', [EquipmentController::class, 'documentFile'], ['permission' => 'equipment.view']);
    $r->post('/equipment/{id}/documents/{documentId}/delete', [EquipmentController::class, 'deleteDocument'], ['permission' => 'documents.manage']);
    $r->post('/equipment/{id}/documents/{documentId}/renewal', [EquipmentController::class, 'documentRenewal'], ['permission' => 'documents.manage']);
    $r->post('/equipment/{id}/document-exemptions', [EquipmentController::class, 'exemptDocument'], ['permission' => 'documents.manage']);
    $r->post('/equipment/{id}/document-exemptions/{typeId}/delete', [EquipmentController::class, 'unexemptDocument'], ['permission' => 'documents.manage']);
    $r->get('/equipment/releases/{id}', [EquipmentController::class, 'release'], ['permission' => 'equipment.view']);
    $r->post('/equipment/{id}/photos', [EquipmentController::class, 'uploadPhoto'], ['permission' => 'equipment.photo']);
    $r->get('/equipment/{id}/photos/{photoId}', [EquipmentController::class, 'photo'], ['permission' => 'equipment.view']);
    $r->post('/equipment/{id}/photos/{photoId}/primary', [EquipmentController::class, 'primaryPhoto'], ['permission' => 'equipment.photo']);
    $r->post('/equipment/{id}/photos/{photoId}/delete', [EquipmentController::class, 'deletePhoto'], ['permission' => 'equipment.manage']);

    // Documents de bord (assurance, contrôle technique, vignette, stationnement…)
    $r->get('/documents', [DocumentController::class, 'index'], ['permission' => 'equipment.view']);
    $r->get('/documents/data', [DocumentController::class, 'data'], ['permission' => 'equipment.view']);
    $r->get('/documents/create', [DocumentController::class, 'create'], ['permission' => 'documents.manage']);
    $r->post('/documents', [DocumentController::class, 'store'], ['permission' => 'documents.manage']);

    // Ordres de travail
    $r->get('/work-orders', [WorkOrderController::class, 'index'], ['permission' => 'workorder.view']);
    $r->get('/work-orders/data', [WorkOrderController::class, 'data'], ['permission' => 'workorder.view']);
    $r->get('/work-orders/assignees', [WorkOrderController::class, 'assignees'], ['permission' => 'workorder.manage']);
    $r->get('/work-orders/create', [WorkOrderController::class, 'create'], ['permission' => 'workorder.create']);
    $r->post('/work-orders', [WorkOrderController::class, 'store'], ['permission' => 'workorder.create']);
    $r->get('/work-orders/{id}', [WorkOrderController::class, 'show'], ['permission' => 'workorder.view']);
    $r->get('/work-orders/{id}/edit', [WorkOrderController::class, 'edit'], ['permission' => 'workorder.manage']);
    $r->post('/work-orders/{id}', [WorkOrderController::class, 'update'], ['permission' => 'workorder.manage']);
    $r->post('/work-orders/{id}/transition', [WorkOrderController::class, 'transition'], ['permission' => 'workorder.execute']);
    $r->post('/work-orders/{id}/parts', [WorkOrderController::class, 'addPart'], ['permission' => 'workorder.execute']);
    $r->post('/work-orders/{id}/technicians', [WorkOrderController::class, 'addTechnician'], ['permission' => 'workorder.execute']);
    $r->post('/work-orders/{id}/technicians/{technicianId}/delete', [WorkOrderController::class, 'removeTechnician'], ['permission' => 'workorder.execute']);
    $r->post('/work-orders/{id}/comments', [WorkOrderController::class, 'comment'], ['permission' => 'workorder.execute']);
    $r->post('/work-orders/{id}/services', [WorkOrderController::class, 'addService'], ['permission' => 'workorder.execute']);

    // Maintenance préventive
    $r->get('/preventive', [PreventiveController::class, 'index'], ['permission' => 'preventive.view']);
    $r->get('/preventive/data', [PreventiveController::class, 'data'], ['permission' => 'preventive.view']);
    $r->get('/preventive/create', [PreventiveController::class, 'create'], ['permission' => 'preventive.manage']);
    $r->post('/preventive', [PreventiveController::class, 'store'], ['permission' => 'preventive.manage']);
    $r->post('/preventive/generate', [PreventiveController::class, 'generate'], ['permission' => 'preventive.manage']);
    $r->get('/preventive/{id}/edit', [PreventiveController::class, 'edit'], ['permission' => 'preventive.manage']);
    $r->post('/preventive/{id}', [PreventiveController::class, 'update'], ['permission' => 'preventive.manage']);
    $r->post('/preventive/{id}/delete', [PreventiveController::class, 'delete'], ['permission' => 'preventive.delete']);

    // Supervision des index (compteurs) et échéances projetées
    $r->get('/meters', [MeterController::class, 'index'], ['permission' => 'equipment.view']);
    $r->get('/meters/screen', [MeterController::class, 'screen'], ['permission' => 'equipment.view']);
    $r->get('/meters/data', [MeterController::class, 'data'], ['permission' => 'equipment.view']);
    $r->get('/meters/{id}/series', [MeterController::class, 'series'], ['permission' => 'equipment.view']);

    // Saisie des index par les encodeurs (téléphone / tablette)
    $r->get('/saisie', [MeterEntryController::class, 'index'], ['permission' => 'meters.entry']);
    $r->get('/saisie/rapport', [MeterEntryController::class, 'report'], ['permission' => 'meters.entry']);
    $r->get('/saisie/supervision', [MeterEntryController::class, 'supervision'], ['permission' => 'meters.entry']);
    $r->get('/saisie/supervision/data', [MeterEntryController::class, 'supervisionData'], ['permission' => 'meters.entry']);
    $r->get('/saisie/supervision/{id}/series', [MeterEntryController::class, 'supervisionSeries'], ['permission' => 'meters.entry']);
    $r->post('/saisie/{id}', [MeterEntryController::class, 'save'], ['permission' => 'meters.entry']);
    $r->post('/saisie/{id}/jour', [MeterEntryController::class, 'backfill'], ['permission' => 'meters.entry']);

    // Répertoire général des index (administrateur) : modification et suppression des relevés
    $r->get('/readings', [MeterReadingController::class, 'index'], ['permission' => 'readings.manage']);
    $r->get('/readings/data', [MeterReadingController::class, 'data'], ['permission' => 'readings.manage']);
    $r->post('/readings/delete', [MeterReadingController::class, 'deleteMany'], ['permission' => 'readings.manage']);
    $r->get('/readings/{id}/edit', [MeterReadingController::class, 'edit'], ['permission' => 'readings.manage']);
    $r->post('/readings/{id}', [MeterReadingController::class, 'update'], ['permission' => 'readings.manage']);
    $r->post('/readings/{id}/delete', [MeterReadingController::class, 'delete'], ['permission' => 'readings.manage']);

    // Pièces détachées
    $r->get('/parts', [PartController::class, 'index'], ['permission' => 'parts.view']);
    $r->get('/parts/data', [PartController::class, 'data'], ['permission' => 'parts.view']);
    $r->get('/parts/create', [PartController::class, 'create'], ['permission' => 'parts.manage']);
    $r->post('/parts', [PartController::class, 'store'], ['permission' => 'parts.manage']);
    $r->get('/parts/{id}', [PartController::class, 'show'], ['permission' => 'parts.view']);
    $r->get('/parts/{id}/edit', [PartController::class, 'edit'], ['permission' => 'parts.manage']);
    $r->post('/parts/{id}', [PartController::class, 'update'], ['permission' => 'parts.manage']);
    $r->post('/parts/{id}/movements', [PartController::class, 'movement'], ['permission' => 'stock.move']);
    $r->post('/parts/{id}/settings', [PartController::class, 'settings'], ['permission' => 'stock.move']);

    // Stocks multisites : fiche de magasin, état consolidé, journal, transferts (transit), inventaires, clôtures
    $r->get('/stock', [StockController::class, 'index'], ['permission' => 'stock.view']);
    $r->get('/stock/data', [StockController::class, 'data'], ['permission' => 'stock.view']);
    $r->get('/stock/journal', [StockController::class, 'journal'], ['permission' => 'stock.view']);
    $r->get('/stock/journal/data', [StockController::class, 'journalData'], ['permission' => 'stock.view']);
    $r->get('/stock/periods', [StockController::class, 'periods'], ['permission' => 'stock.view']);
    $r->post('/stock/periods', [StockController::class, 'closePeriod'], ['permission' => 'stock.close']);
    $r->get('/closings', [\App\Controllers\ClosingController::class, 'index'], ['permission' => 'closings.view']);
    // Listes de détail des tableaux de bord
    $r->get('/dashboard/drill/payments', [\App\Controllers\DashboardDrillController::class, 'payments'], ['permission' => 'finance.view']);
    $r->get('/dashboard/drill/invoices', [\App\Controllers\DashboardDrillController::class, 'invoices'], ['permission' => 'finance.view']);
    $r->get('/dashboard/drill/commitments', [\App\Controllers\DashboardDrillController::class, 'commitments'], ['permission' => 'finance.view']);
    $r->get('/dashboard/drill/cost-lines', [\App\Controllers\DashboardDrillController::class, 'costLines'], ['permission' => 'costs.view']);
    $r->get('/dashboard/drill/stock-values', [\App\Controllers\DashboardDrillController::class, 'stockValues'], ['permission' => 'stock.view']);
    $r->get('/stock/transfers', [StockTransferController::class, 'index'], ['permission' => 'stock.view']);
    $r->get('/stock/transfers/create', [StockTransferController::class, 'create'], ['permission' => 'stock.transfer']);
    $r->post('/stock/transfers', [StockTransferController::class, 'store'], ['permission' => 'stock.transfer']);
    $r->get('/stock/transfers/{id}', [StockTransferController::class, 'show'], ['permission' => 'stock.view']);
    $r->post('/stock/transfers/{id}/ship', [StockTransferController::class, 'ship'], ['permission' => 'stock.move']);
    $r->post('/stock/transfers/{id}/receive', [StockTransferController::class, 'receive'], ['permission' => 'stock.move']);
    $r->post('/stock/transfers/{id}/cancel', [StockTransferController::class, 'cancel'], ['permission' => 'stock.transfer']);
    $r->get('/stock/inventories', [StockInventoryController::class, 'index'], ['permission' => 'stock.view']);
    $r->post('/stock/inventories', [StockInventoryController::class, 'store'], ['permission' => 'stock.inventory']);
    $r->get('/stock/inventories/{id}', [StockInventoryController::class, 'show'], ['permission' => 'stock.view']);
    $r->post('/stock/inventories/{id}/counts', [StockInventoryController::class, 'counts'], ['permission' => 'stock.inventory']);
    $r->post('/stock/inventories/{id}/cancel', [StockInventoryController::class, 'cancel'], ['permission' => 'stock.inventory']);

    // États de besoins (EDB), devis, commandes, réceptions, rapports mensuels
    $r->get('/edb', [PurchaseController::class, 'index'], ['permission' => 'edb.view']);
    $r->get('/edb/data', [PurchaseController::class, 'data'], ['permission' => 'edb.view']);
    $r->get('/edb/reports', [PurchaseController::class, 'reports'], ['permission' => 'edb.view']);
    $r->get('/edb/create', [PurchaseController::class, 'create'], ['permission' => 'edb.create']);
    $r->post('/edb', [PurchaseController::class, 'store'], ['permission' => 'edb.create']);
    $r->get('/edb/{id}', [PurchaseController::class, 'show'], ['permission' => 'edb.view']);
    $r->post('/edb/{id}/cancel', [PurchaseController::class, 'cancel'], ['permission' => 'edb.create']);
    $r->post('/edb/{id}/issue', [PurchaseController::class, 'issue'], ['permission' => 'stock.move']);
    $r->post('/edb/{id}/transfer', [PurchaseController::class, 'transfer'], ['permission' => 'stock.transfer']);
    $r->post('/edb/{id}/quotes', [PurchaseController::class, 'addQuote'], ['permission' => 'purchase.manage']);
    $r->post('/edb/{id}/quotes/select', [PurchaseController::class, 'selectQuote'], ['permission' => 'purchase.manage']);
    $r->post('/edb/{id}/orders', [PurchaseController::class, 'createOrder'], ['permission' => 'purchase.manage']);
    $r->get('/purchases', [PurchaseController::class, 'orders'], ['permission' => 'edb.view']);
    $r->get('/purchases/{id}', [PurchaseController::class, 'order'], ['permission' => 'edb.view']);
    $r->post('/purchases/{id}/receive', [PurchaseController::class, 'receive'], ['permission' => 'stock.move']);
    $r->post('/purchases/{id}/cancel', [PurchaseController::class, 'cancelOrder'], ['permission' => 'purchase.manage']);

    // Transferts d'engins entre sites : demande, check-list technique, OTV, départ, procès-verbal de réception
    $r->get('/fleet-transfers', [FleetTransferController::class, 'index'], ['permission' => 'fleet.transfer.view']);
    $r->get('/fleet-transfers/create', [FleetTransferController::class, 'create'], ['permission' => 'fleet.transfer']);
    $r->post('/fleet-transfers', [FleetTransferController::class, 'store'], ['permission' => 'fleet.transfer']);
    $r->get('/fleet-transfers/{id}', [FleetTransferController::class, 'show'], ['permission' => 'fleet.transfer.view']);
    $r->post('/fleet-transfers/{id}/check', [FleetTransferController::class, 'check'], ['permission' => 'fleet.check']);
    $r->post('/fleet-transfers/{id}/depart', [FleetTransferController::class, 'depart'], ['permission' => 'fleet.dispatch']);
    $r->post('/fleet-transfers/{id}/receive', [FleetTransferController::class, 'receive'], ['permission' => 'fleet.dispatch']);
    $r->post('/fleet-transfers/{id}/cancel', [FleetTransferController::class, 'cancel'], ['permission' => 'fleet.transfer']);

    // Finance : factures, décaissements, caisse, rapprochement, états de dépenses
    $r->get('/finance', [FinanceController::class, 'index'], ['permission' => 'finance.view']);
    $r->get('/finance/invoices', [FinanceController::class, 'invoices'], ['permission' => 'finance.view']);
    $r->post('/finance/invoices', [FinanceController::class, 'storeInvoice'], ['permission' => 'finance.invoice']);
    $r->post('/finance/invoices/{id}/cancel', [FinanceController::class, 'cancelInvoice'], ['permission' => 'finance.invoice']);
    $r->get('/finance/payments', [FinanceController::class, 'payments'], ['permission' => 'finance.view']);
    $r->get('/finance/payments/create', [FinanceController::class, 'createPayment'], ['permission' => 'finance.request']);
    $r->post('/finance/payments', [FinanceController::class, 'storePayment'], ['permission' => 'finance.request']);
    $r->get('/finance/payments/{id}', [FinanceController::class, 'showPayment'], ['permission' => 'finance.view']);
    $r->post('/finance/payments/{id}/pay', [FinanceController::class, 'pay'], ['permission' => 'finance.pay']);
    $r->post('/finance/payments/{id}/justify', [FinanceController::class, 'justify'], ['permission' => 'finance.view']);
    $r->post('/finance/payments/{id}/cancel', [FinanceController::class, 'cancelPayment'], ['permission' => 'finance.request']);
    $r->get('/finance/cash', [FinanceController::class, 'cashJournal'], ['permission' => 'finance.view']);
    $r->post('/finance/cash', [FinanceController::class, 'storeRegister'], ['permission' => 'cash.manage']);
    $r->post('/finance/cash/{id}/entries', [FinanceController::class, 'cashEntry'], ['permission' => 'cash.manage']);
    $r->post('/finance/cash/{id}/close', [FinanceController::class, 'cashClose'], ['permission' => 'cash.manage']);
    $r->get('/finance/reports', [FinanceController::class, 'reports'], ['permission' => 'finance.view']);
    $r->get('/finance/reports/data', [FinanceController::class, 'reportsData'], ['permission' => 'finance.view']);
    $r->get('/finance/reconciliation', [FinanceController::class, 'reconciliation'], ['permission' => 'finance.view']);

    // Carburant (ravitaillements) et coût mensuel par équipement
    $r->get('/fuel', [EquipmentCostController::class, 'fuel'], ['permission' => 'fuel.view']);
    $r->get('/fuel/data', [EquipmentCostController::class, 'fuelData'], ['permission' => 'fuel.view']);
    $r->get('/fuel/create', [EquipmentCostController::class, 'createRefill'], ['permission' => 'fuel.record']);
    $r->post('/fuel', [EquipmentCostController::class, 'storeRefill'], ['permission' => 'fuel.record']);
    $r->get('/costs', [EquipmentCostController::class, 'index'], ['permission' => 'costs.view']);
    $r->get('/costs/data', [EquipmentCostController::class, 'data'], ['permission' => 'costs.view']);
    $r->post('/costs/close', [EquipmentCostController::class, 'close'], ['permission' => 'costs.close']);
    $r->post('/costs/charges', [EquipmentCostController::class, 'storeCharge'], ['permission' => 'costs.charge']);
    $r->get('/costs/{id}', [EquipmentCostController::class, 'show'], ['permission' => 'costs.view']);
    $r->post('/costs/{id}/note', [EquipmentCostController::class, 'note'], ['permission' => 'costs.charge']);

    // Mes validations (circuit d'approbation)
    $r->get('/approvals', [ApprovalController::class, 'index'], ['permission' => 'approvals.use']);
    $r->post('/approvals/{id}/act', [ApprovalController::class, 'act'], ['permission' => 'approvals.use']);

    // Pilotage : alertes, planning, rapports de direction
    $r->get('/alerts', [ManagementController::class, 'alerts'], ['permission' => 'dashboard.view']);
    $r->get('/notifications', [NotificationController::class, 'index'], ['permission' => 'dashboard.view']);
    $r->get('/search', [SearchController::class, 'index'], ['permission' => 'dashboard.view']);
    $r->get('/planning', [ManagementController::class, 'planning'], ['permission' => 'dashboard.view']);
    $r->get('/reports', [ManagementController::class, 'reports'], ['permission' => 'reports.view']);

    // Besoins achats & magasin
    $r->get('/procurement', [ProcurementController::class, 'index'], ['permission' => 'parts.view']);
    $r->get('/procurement/data', [ProcurementController::class, 'data'], ['permission' => 'parts.view']);

    // Écrans terrain (mobile)
    $r->get('/terrain', [FieldController::class, 'index'], ['permission' => 'field.use']);
    $r->get('/terrain/declarer', [FieldController::class, 'declare'], ['permission' => 'workorder.create']);
    $r->post('/terrain/declarer', [FieldController::class, 'storeDeclaration'], ['permission' => 'workorder.create']);
    $r->get('/terrain/ot/{id}', [FieldController::class, 'show'], ['permission' => 'field.use']);
    $r->get('/terrain/engins', [FieldController::class, 'equipment'], ['permission' => 'field.use']);
    $r->get('/terrain/engins/{id}', [FieldController::class, 'showEquipment'], ['permission' => 'field.use']);

    // Équipes par shift, personnel, affectations chauffeurs / opérateurs
    $r->get('/teams', [WorkforceController::class, 'teams'], ['permission' => 'personnel.view']);
    $r->get('/teams/create', [WorkforceController::class, 'createTeam'], ['permission' => 'personnel.manage']);
    $r->post('/teams', [WorkforceController::class, 'storeTeam'], ['permission' => 'personnel.manage']);
    $r->get('/teams/{id}/edit', [WorkforceController::class, 'editTeam'], ['permission' => 'personnel.manage']);
    $r->post('/teams/{id}', [WorkforceController::class, 'updateTeam'], ['permission' => 'personnel.manage']);
    $r->get('/personnel', [WorkforceController::class, 'personnel'], ['permission' => 'personnel.view']);
    $r->get('/personnel/data', [WorkforceController::class, 'personnelData'], ['permission' => 'personnel.view']);
    $r->get('/personnel/create', [WorkforceController::class, 'createPerson'], ['permission' => 'personnel.manage']);
    $r->post('/personnel', [WorkforceController::class, 'storePerson'], ['permission' => 'personnel.manage']);
    $r->get('/personnel/{id}/edit', [WorkforceController::class, 'editPerson'], ['permission' => 'personnel.manage']);
    $r->post('/personnel/{id}', [WorkforceController::class, 'updatePerson'], ['permission' => 'personnel.manage']);
    $r->post('/equipment/{id}/assignments', [WorkforceController::class, 'assign'], ['permission' => 'equipment.assign']);
    $r->post('/equipment/{id}/assignments/{assignmentId}/end', [WorkforceController::class, 'unassign'], ['permission' => 'equipment.assign']);

    // Fenêtre par site (tout utilisateur, dans son périmètre)
    $r->get('/sites/{id}', [SiteOverviewController::class, 'show'], ['permission' => 'dashboard.view']);

    // Référentiel marques / modèles
    $r->get('/catalog', [CatalogController::class, 'index'], ['permission' => 'equipment.manage']);
    $r->post('/catalog/brands', [CatalogController::class, 'storeBrand'], ['permission' => 'equipment.manage']);
    $r->post('/catalog/brands/{id}', [CatalogController::class, 'updateBrand'], ['permission' => 'equipment.manage']);
    $r->post('/catalog/models', [CatalogController::class, 'storeModel'], ['permission' => 'equipment.manage']);
    $r->post('/catalog/models/{id}', [CatalogController::class, 'updateModel'], ['permission' => 'equipment.manage']);
    $r->post('/catalog/categories/{id}', [CatalogController::class, 'updateCategory'], ['permission' => 'equipment.manage']);
    $r->post('/catalog/models/{id}/fuel', [CatalogController::class, 'modelFuel'], ['permission' => 'equipment.manage']);
    $r->post('/catalog/gauges', [CatalogController::class, 'storeGauge'], ['permission' => 'equipment.manage']);
    $r->post('/catalog/gauges/{id}', [CatalogController::class, 'updateGauge'], ['permission' => 'equipment.manage']);

    // Référentiels communs : projets, magasins, départements, centres de coûts, fournisseurs, transporteurs, modes de paiement
    $r->get('/references', [ReferenceController::class, 'home'], ['permission' => 'reference.view']);
    $r->get('/references/{type}', [ReferenceController::class, 'index'], ['permission' => 'reference.view']);
    $r->get('/references/{type}/create', [ReferenceController::class, 'create'], ['permission' => 'reference.manage']);
    $r->get('/references/{type}/import', [ReferenceController::class, 'importForm'], ['permission' => 'reference.manage']);
    $r->post('/references/{type}/import/preview', [ReferenceController::class, 'importPreview'], ['permission' => 'reference.manage']);
    $r->post('/references/{type}/import', [ReferenceController::class, 'importRun'], ['permission' => 'reference.manage']);
    $r->post('/references/{type}', [ReferenceController::class, 'store'], ['permission' => 'reference.manage']);
    $r->get('/references/{type}/{id}/edit', [ReferenceController::class, 'edit'], ['permission' => 'reference.manage']);
    $r->post('/references/{type}/{id}', [ReferenceController::class, 'update'], ['permission' => 'reference.manage']);

    // Délégations de fonction
    $r->get('/delegations', [DelegationController::class, 'index'], ['permission' => 'delegations.manage']);
    $r->post('/delegations', [DelegationController::class, 'store'], ['permission' => 'delegations.manage']);
    $r->post('/delegations/{id}/revoke', [DelegationController::class, 'revoke'], ['permission' => 'delegations.manage']);

    // Sauvegardes et tests de restauration
    $r->get('/backups', [\App\Controllers\BackupController::class, 'index'], ['permission' => 'backups.manage']);
    $r->post('/backups', [\App\Controllers\BackupController::class, 'store'], ['permission' => 'backups.manage']);
    $r->post('/backups/{id}/verify', [\App\Controllers\BackupController::class, 'verify'], ['permission' => 'backups.manage']);
    $r->get('/backups/{id}/download', [\App\Controllers\BackupController::class, 'download'], ['permission' => 'backups.manage']);

    // Pièces jointes (contrôle du périmètre dans le service)
    $r->get('/attachments/{id}', [AttachmentController::class, 'show'], ['permission' => 'attachments.view']);

    // Administration
    $r->get('/sites', [SiteController::class, 'index'], ['permission' => 'sites.manage']);
    $r->get('/sites/data', [SiteController::class, 'data'], ['permission' => 'sites.manage']);
    $r->get('/sites/create', [SiteController::class, 'create'], ['permission' => 'sites.manage']);
    $r->post('/sites', [SiteController::class, 'store'], ['permission' => 'sites.manage']);
    $r->get('/sites/{id}/edit', [SiteController::class, 'edit'], ['permission' => 'sites.manage']);
    $r->post('/sites/{id}', [SiteController::class, 'update'], ['permission' => 'sites.manage']);

    $r->get('/users', [UserController::class, 'index'], ['permission' => 'users.manage']);
    $r->get('/users/data', [UserController::class, 'data'], ['permission' => 'users.manage']);
    $r->get('/users/create', [UserController::class, 'create'], ['permission' => 'users.manage']);
    $r->post('/users', [UserController::class, 'store'], ['permission' => 'users.manage']);
    $r->get('/users/{id}/edit', [UserController::class, 'edit'], ['permission' => 'users.manage']);
    $r->post('/users/{id}', [UserController::class, 'update'], ['permission' => 'users.manage']);

    // Paramètres : envoi d'e-mails et alarmes par e-mail
    $r->get('/settings/mail', [SettingsController::class, 'mail'], ['permission' => 'settings.manage']);
    $r->post('/settings/mail', [SettingsController::class, 'saveMail'], ['permission' => 'settings.manage']);
    $r->post('/settings/mail/test', [SettingsController::class, 'testMail'], ['permission' => 'settings.manage']);
    $r->post('/settings/mail/alerts', [SettingsController::class, 'sendAlerts'], ['permission' => 'settings.manage']);

    $r->get('/activity', [ActivityController::class, 'index'], ['permission' => 'activity.view']);
    $r->get('/activity/data', [ActivityController::class, 'data'], ['permission' => 'activity.view']);
    $r->get('/activity/summary', [ActivityController::class, 'summary'], ['permission' => 'activity.view']);
};
