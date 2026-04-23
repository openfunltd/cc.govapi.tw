<?php

class CollectionController extends MiniEngine_Controller
{
    public function listAction($type, $tab = null)
    {
        $this->view->type = $type;
        $this->view->features = TypeHelper::getCollectionFeatures($type);
        if (!$tab) {
            $tab = key($this->view->features);
        }
        $this->view->tab = $tab;
        if (!array_key_exists($tab, $this->view->features)) {
            throw new Exception('Invalid tab: ' . $tab);
        }
        if (method_exists($this, "list_{$type}_{$tab}")) {
            $this->{"list_{$type}_{$tab}"}();
        }
    }

    public function completenessAction($cc_code = null)
    {
        $this->view->type = 'completeness';
        if ($cc_code) {
            // 層 2：單一議會屆期細圖
            $this->view->cc_code = $cc_code;
            $result = CCAPI::apiQuery('/completeness/' . rawurlencode($cc_code), '議會完整度資料');
            $this->view->council = $result->data ?? null;
        } else {
            // 層 1：全部議會大圖
            $result = CCAPI::apiQuery('/completenesses?limit=50', '所有議會完整度資料');
            $this->view->councils = $result->completenesses ?? [];
        }
    }

    public function itemAction($type, $id, $tab = null)
    {
        $this->view->type = $type;
        $this->view->id = $id;
        $this->view->data = TypeHelper::getDataByID($type, $id);
        $this->view->features = TypeHelper::getItemFeatures($type);
        if (!$tab) {
            $tab = key($this->view->features);
        }
        $this->view->tab = $tab;
        if (!array_key_exists($tab, $this->view->features)) {
            throw new Exception('Invalid tab: ' . $tab);
        }
        if (method_exists($this, "item_{$type}_{$tab}")) {
            $this->{"item_{$type}_{$tab}"}();
        }
    }
}
