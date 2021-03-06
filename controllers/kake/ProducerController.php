<?php

namespace service\controllers\kake;

use service\components\Helper;
use service\controllers\MainController;
use service\models\kake\Attachment;
use service\models\kake\ProducerApply;
use service\models\kake\ProducerLog;
use service\models\kake\ProducerProduct;
use service\models\kake\ProducerQuota;
use service\models\kake\ProducerSetting;
use service\models\kake\ProducerWithdraw;
use service\models\kake\ProductProducer;
use service\models\kake\User;
use yii;

/**
 * Producer controller
 *
 * @author    Leon <jiangxilee@gmail.com>
 * @copyright 2017-06-23 13:54:21
 */
class ProducerController extends MainController
{
    /**
     * 结算
     *
     * @access public
     *
     * @param array $log
     * @param float $quota
     * @param integer $user_id
     *
     * @return void
     */
    public function actionSettlement($log, $quota, $user_id)
    {
        $producerLog = new ProducerLog();
        $log = Helper::parseJsonString($log);

        $producerQuota = new ProducerQuota();
        $record = $producerQuota->first(function ($ar) use ($quota, $user_id) {
            /**
             * @var $ar yii\db\Query
             */
            $ar->where([
                'producer_id' => $user_id,
                'state' => 1
            ]);
            $ar->orderBy('add_time DESC');

            return $ar;
        }, Yii::$app->params['use_cache']);

        $result = $producerLog->trans(function () use ($producerLog, $log, $quota, $user_id, $producerQuota, $record) {

            // 佣金变更
            $beforeQuota = empty($record) ? 0 : $record['quota'];
            $afterQuota = $beforeQuota + $quota;

            $result = $producerQuota->add([
                'producer_id' => $user_id,
                'quota' => $afterQuota
            ]);

            if (!$result['state']) {
                throw new yii\db\Exception($result['info']);
            }

            // 日志和状态
            foreach ($log as $id => $item) {
                $item['log_commission_quota'] = (string) substr($item['log_commission_quota'], 0, 16);
                $item['state'] = 0;

                $producerLog->edit([
                    'id' => $id,
                    'producer_id' => $user_id
                ], $item);
            }

            return compact('beforeQuota', 'afterQuota');
        }, '分销结算');

        if (!$result['state']) {
            $this->fail($result['info']);
        }

        $this->success($result['data']);
    }

    /**
     * 提现申请处理
     *
     * @access public
     *
     * @param integer $id
     *
     * @return void
     */
    public function actionWithdraw($id)
    {
        $producerWithdraw = new ProducerWithdraw();
        $withdraw = $producerWithdraw->first([
            'id' => $id,
            'state' => 1
        ], Yii::$app->params['use_cache']);

        if (empty($withdraw)) {
            $this->fail('withdraw request not exists');
        }

        $producerQuota = new ProducerQuota();
        $quota = $producerQuota->first(function ($ar) use ($withdraw) {
            /**
             * @var $ar yii\db\Query
             */
            $ar->where([
                'producer_id' => $withdraw['producer_id'],
                'state' => 1
            ]);
            $ar->orderBy('add_time DESC');

            return $ar;
        }, Yii::$app->params['use_cache']);

        $have = empty($quota) ? 0 : $quota['quota'];
        $surplusQuota = $have - $withdraw['withdraw'];

        if ($surplusQuota < 0) {
            $this->fail('withdraw must less than quota');
        }

        $quota = [
            'producer_id' => $withdraw['producer_id'],
            'quota' => $surplusQuota
        ];

        $result = $producerQuota->trans(function () use ($producerQuota, $producerWithdraw, $quota, $id) {
            $producerQuota->add($quota);
            $producerWithdraw->edit([
                'id' => $id,
                'state' => 1
            ], ['state' => 2]);
        }, '完成提现处理');

        if (!$result['state']) {
            $this->fail($result['info']);
        }

        $this->success(['quota' => $surplusQuota]);
    }

    /**
     * 获取分销产品的 product_ids
     *
     * @param integer $producer_id
     * @param integer $limit
     *
     * @return array
     */
    public function listProductIds($producer_id, $limit = null)
    {
        $producerProduct = new ProducerProduct();
        $product = $producerProduct->all(function ($list) use ($producer_id, $limit) {
            /**
             * @var $list yii\db\Query
             */
            $list->where([
                'producer_id' => $producer_id,
                'state' => 1
            ]);
            $list->orderBy('update_time DESC');
            $list->select('product_id');
            $limit && $list->limit($limit);

            return $list;
        }, null, Yii::$app->params['use_cache']);
        $product = array_column($product, 'product_id');

        return $product;
    }

    /**
     * 获取分销产品的 product_ids
     *
     * @param integer $producer_id
     * @param integer $limit
     *
     * @return void
     */
    public function actionListProductIds($producer_id, $limit = null)
    {
        $this->success($this->listProductIds($producer_id, $limit));
    }

    /**
     * 通过申请
     *
     * @param integer $id
     *
     * @return void
     * @throws yii\db\Exception
     */
    public function actionAgreeApply($id)
    {
        $producerApplyModel = new ProducerApply();
        $apply = $producerApplyModel::findOne([
            'id' => $id,
            'state' => 1
        ]);
        if (empty($apply)) {
            $this->fail('apply does not exist');
        }

        $userModel = new User();
        $user = $userModel::findOne([
            'id' => $apply->user_id,
            'state' => 1
        ]);

        if (empty($user)) {
            $this->fail('abnormal data');
        }

        if ($user['role'] > 0) {
            $apply->state = 0;
            if (!$apply->update()) {
                $this->fail(current($apply->getFirstErrors()));
            }
            $this->fail('user already has the ability to distribute');
        }

        $producerProduct = (new ProductProducer())->all(function ($ar) {
            /**
             * @var $ar yii\db\Query
             */
            $ar->where(['state' => 1]);
            $ar->groupBy('product_id');
            $ar->select([
                'product_id',
                'type'
            ]);

            return $ar;
        });

        // 注册分销商
        $result = $userModel->trans(function () use ($producerApplyModel, $user, $apply, $producerProduct) {

            // update table `producer_apply`
            $apply->state = 0;
            if (!$apply->update()) {
                throw new yii\db\Exception(current($apply->getFirstErrors()));
            }

            // update table `user`
            $user->role = 10;
            $user->phone = $apply->phone;

            if (!$user->update()) {
                throw new yii\db\Exception(current($user->getFirstErrors()));
            }

            // add table `producer`
            $producerModel = new ProducerSetting();
            $producerModel->attributes = [
                'producer_id' => $apply->user_id,
                'name' => $apply->name,
                'logo_attachment_id' => $apply->attachment_id,
                'account_type' => 1,
                'account_number' => 'AUTO:' . $apply->phone,
            ];

            if (!$producerModel->save()) {
                throw new yii\db\Exception(current($producerModel->getFirstErrors()));
            }

            // add table `producer_product`
            $items = [];
            foreach ($producerProduct as $item) {
                $items[] = [
                    $apply->user_id,
                    $item['product_id'],
                    $items['type']
                ];
            }

            $effect = (new ProducerProduct())->batchAdd([
                'producer_id',
                'product_id',
                'type'
            ], $items);

            return $effect;

        }, '通过申请分销商');

        if (!$result['state']) {
            $this->fail($result['info']);
        }

        $avatar = (new Attachment())->first(['id' => $apply->attachment_id]);
        $this->success(compact('avatar'));
    }
}