<?php

namespace justinholtweb\icecube\assetbundles\icecube;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class IcecubeAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['icecube.js'];
        $this->css = ['icecube.css'];

        parent::init();
    }
}
