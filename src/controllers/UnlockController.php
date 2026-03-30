<?php

namespace justinholtweb\icecube\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\icecube\Icecube;
use justinholtweb\icecube\records\LockRecord;
use justinholtweb\icecube\services\Auth;
use yii\web\Response;

class UnlockController extends Controller
{
    /**
     * POST icecube/unlock
     * AJAX endpoint — validate password and grant session unlock.
     */
    public function actionValidate(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $targetType = $request->getRequiredBodyParam('targetType');
        $targetId = (int)$request->getRequiredBodyParam('targetId');
        $action = $request->getRequiredBodyParam('action'); // edit|delete
        $password = $request->getRequiredBodyParam('password');

        $auth = Icecube::getInstance()->auth;
        $success = $auth->attemptUnlock($targetType, $targetId, $action, $password);

        if ($success) {
            return $this->asJson(['success' => true]);
        }

        return $this->asJson([
            'success' => false,
            'error' => 'Invalid unlock password.',
        ]);
    }

    /**
     * GET icecube/locks
     * Lock management screen.
     */
    public function actionManage(): Response
    {
        $this->requirePermission('icecube:manageLocks');

        $locks = Icecube::getInstance()->locks->getAllLocks();

        return $this->renderTemplate('icecube/_locks/index', [
            'locks' => $locks,
        ]);
    }

    /**
     * POST icecube/locks/save
     * Create or update a lock.
     */
    public function actionSaveLock(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('icecube:manageLocks');

        $request = Craft::$app->getRequest();
        $id = $request->getBodyParam('id');

        $record = $id ? LockRecord::findOne($id) : new LockRecord();
        if (!$record) {
            $record = new LockRecord();
        }

        $record->targetType = $request->getRequiredBodyParam('targetType');
        $record->targetId = $request->getBodyParam('targetId') ?: null;
        $record->scope = $request->getBodyParam('scope', 'element');
        $record->scopeId = $request->getBodyParam('scopeId') ?: null;
        $record->lockEdit = (bool)$request->getBodyParam('lockEdit', true);
        $record->lockDelete = (bool)$request->getBodyParam('lockDelete', true);
        $record->notes = $request->getBodyParam('notes');
        $record->enabled = (bool)$request->getBodyParam('enabled', true);

        // Per-lock password (optional)
        $password = $request->getBodyParam('password');
        if (!empty($password)) {
            $record->passwordHash = Auth::hashPassword($password);
        }

        if (!Icecube::getInstance()->locks->saveLock($record)) {
            Craft::$app->getSession()->setError('Could not save lock.');
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Lock saved.');
        return $this->redirectToPostedUrl();
    }

    /**
     * POST icecube/locks/delete
     * Delete a lock.
     */
    public function actionDeleteLock(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('icecube:manageLocks');

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        Icecube::getInstance()->locks->deleteLockById($id);

        Craft::$app->getSession()->setNotice('Lock removed.');
        return $this->redirectToPostedUrl();
    }
}
