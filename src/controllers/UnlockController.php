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

        $auth = Icecube::getInstance()->getAuth();
        $success = $auth->attemptUnlock($targetType, $targetId, $action, $password);

        if ($success) {
            return $this->asJson(['success' => true]);
        }

        return $this->asJson([
            'success' => false,
            'error' => Craft::t('icecube', 'Invalid unlock password.'),
        ]);
    }

    /**
     * GET icecube/locks
     * Lock management screen.
     */
    public function actionManage(): Response
    {
        $this->requirePermission('icecube:manageLocks');

        $locks = Icecube::getInstance()->getLocks()->getAllLocks();

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

        $record = $id ? LockRecord::findOne($id) : null;
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

        if (!Icecube::getInstance()->getLocks()->saveLock($record)) {
            Craft::$app->getSession()->setError(Craft::t('icecube', 'Couldn’t save lock.'));
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice(Craft::t('icecube', 'Lock saved.'));
        return $this->redirectToPostedUrl();
    }

    /**
     * POST icecube/locks/inline-save
     * JSON endpoint — create or update an element-scope lock for a specific target.
     */
    public function actionInlineSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('icecube:manageLocks');

        $request = Craft::$app->getRequest();
        $targetType = $request->getRequiredBodyParam('targetType');
        $targetId = (int)$request->getRequiredBodyParam('targetId');

        // Find existing element-scope lock or create a new one
        $record = Icecube::getInstance()->getLocks()->getDirectLock($targetType, $targetId) ?? new LockRecord();

        $record->targetType = $targetType;
        $record->targetId = $targetId;
        $record->scope = 'element';
        $record->scopeId = null;
        $record->lockEdit = (bool)$request->getBodyParam('lockEdit', true);
        $record->lockDelete = (bool)$request->getBodyParam('lockDelete', true);
        $record->notes = $request->getBodyParam('notes') ?: null;
        $record->enabled = true;

        $password = $request->getBodyParam('password');
        if (!empty($password)) {
            $record->passwordHash = Auth::hashPassword($password);
        }

        if (!Icecube::getInstance()->getLocks()->saveLock($record)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('icecube', 'Couldn’t save lock.'),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'lock' => [
                'id' => $record->id,
                'lockEdit' => (bool)$record->lockEdit,
                'lockDelete' => (bool)$record->lockDelete,
                'notes' => $record->notes,
                'hasPassword' => !empty($record->passwordHash),
            ],
        ]);
    }

    /**
     * POST icecube/locks/inline-delete
     * JSON endpoint — remove the element-scope lock for a target.
     */
    public function actionInlineDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('icecube:manageLocks');

        $request = Craft::$app->getRequest();
        $targetType = $request->getRequiredBodyParam('targetType');
        $targetId = (int)$request->getRequiredBodyParam('targetId');

        $record = Icecube::getInstance()->getLocks()->getDirectLock($targetType, $targetId);
        if ($record) {
            $record->delete();
        }

        return $this->asJson(['success' => true]);
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
        Icecube::getInstance()->getLocks()->deleteLockById($id);

        Craft::$app->getSession()->setNotice(Craft::t('icecube', 'Lock removed.'));
        return $this->redirectToPostedUrl();
    }
}
