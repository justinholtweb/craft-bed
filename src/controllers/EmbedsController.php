<?php

namespace justinholtweb\bed\controllers;

use Craft;
use craft\db\Query;
use craft\helpers\Cp;
use craft\web\Controller;
use justinholtweb\bed\models\Settings;
use justinholtweb\bed\Plugin;
use justinholtweb\bed\records\SlotRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The control panel side: what Bed has seen, what it measured, and how sure it is.
 *
 * This screen exists because "it makes your embeds faster, trust us" is not a feature. An
 * administrator should be able to see which embed on which page is being reserved space for, how
 * many real page views that number came from, and whether Bed thinks it is above the fold.
 */
class EmbedsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $provider = trim((string)$request->getQueryParam('provider', ''));
        $siteId = (int)$request->getQueryParam('siteId', 0);
        $page = max(1, (int)$request->getQueryParam('page', 1));
        $perPage = 50;

        $query = $plugin->ledger->query();

        if ($provider !== '') {
            $query->andWhere(['slots.provider' => $provider]);
        }

        if ($siteId > 0) {
            $query->andWhere(['slots.siteId' => $siteId]);
        }

        $total = (int)(clone $query)->count();

        $slots = $query
            ->orderBy(['slots.lastSeen' => SORT_DESC, 'slots.id' => SORT_DESC])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return $this->renderTemplate('bed/embeds/index', [
            'slots' => $slots,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pages' => max(1, (int)ceil($total / $perPage)),
            'provider' => $provider,
            'siteId' => $siteId,
            'providerCounts' => $plugin->ledger->stats()['providers'],
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
            'plugin' => $plugin,
        ]);
    }

    public function actionDetail(int $slotId): Response
    {
        $plugin = Plugin::getInstance();

        $slot = $plugin->ledger->query()->andWhere(['slots.id' => $slotId])->one();

        if ($slot === null) {
            throw new NotFoundHttpException('No such embed.');
        }

        $measurements = $plugin->ledger->measurements($slotId);
        $provider = $plugin->providers->byHandle((string)$slot['provider']);

        return $this->renderTemplate('bed/embeds/detail', [
            'slot' => $slot,
            'measurements' => $measurements,
            'provider' => $provider,
            'strategy' => $plugin->getSettings()->reserveStrategy,
            'sampleTarget' => $plugin->getSettings()->sampleTarget,
            'breakpoints' => Settings::BREAKPOINTS,
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
            'siblings' => (new Query())
                ->from(['slots' => SlotRecord::TABLE])
                ->select(['id', 'uri', 'position', 'samples'])
                ->where(['embedKey' => $slot['embedKey']])
                ->andWhere(['not', ['id' => $slotId]])
                ->limit(20)
                ->all(),
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $slotId = (int)Craft::$app->getRequest()->getRequiredBodyParam('slotId');

        Craft::$app->getDb()->createCommand()->delete(SlotRecord::TABLE, ['id' => $slotId])->execute();

        $this->setSuccessFlash(Craft::t('bed', 'Measurements cleared.'));

        return $this->redirect('bed/embeds');
    }

    public function actionPurge(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $siteId = (int)Craft::$app->getRequest()->getBodyParam('siteId', 0);
        $count = Plugin::getInstance()->ledger->purge($siteId > 0 ? $siteId : null);

        $this->setSuccessFlash(Craft::t('bed', '{count} measured embeds cleared.', ['count' => $count]));

        return $this->redirect('bed/embeds');
    }

    public function actionPrune(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $count = Plugin::getInstance()->ledger->prune();

        $this->setSuccessFlash(Craft::t('bed', '{count} stale embeds pruned.', ['count' => $count]));

        return $this->redirect('bed/embeds');
    }
}
