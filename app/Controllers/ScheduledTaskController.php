<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Services\MailService;
use App\Services\PreventiveService;
use App\Services\ScheduledTaskService;

/** Tâches planifiées : état de santé, journal des passages et lancement manuel (administrateur). */
final class ScheduledTaskController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly ScheduledTaskService $tasks,
        private readonly PreventiveService $preventive,
        private readonly MailService $mail,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        return $this->render('settings/tasks', [
            'title' => t('nav.scheduled_tasks'),
            'tasks' => $this->tasks->overview(),
            'recent' => $this->tasks->recent(),
            'maxAge' => ScheduledTaskService::MAX_AGE_HOURS,
        ]);
    }

    /** Lancement immédiat (journalisé « manuel ») ; la sauvegarde se lance depuis sa propre page. */
    public function run(Request $request, array $params): Response
    {
        $task = (string) ($params['task'] ?? '');
        $jobs = [
            'preventive' => fn (): string => t('tasks.result_preventive', ['count' => count($this->preventive->generateDue())]),
            'alerts' => function (): string {
                $count = $this->mail->sendAlerts();

                return $count > 0 ? t('mail.alerts_sent', ['count' => $count]) : t('mail.alerts_none');
            },
        ];
        if (!isset($jobs[$task])) {
            throw HttpException::notFound();
        }
        try {
            $message = $this->tasks->run($task, $jobs[$task], 'manual');
        } catch (ValidationException $e) {
            throw Validator::fail('task', 'task_failed', ['error' => implode(' ', $e->errors)]);
        } catch (\RuntimeException $e) {
            throw Validator::fail('task', 'task_failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);
        }

        return $this->redirect('/settings/tasks', $message);
    }
}
