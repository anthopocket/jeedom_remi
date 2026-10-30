<?php
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function remi_install() {
}

function remi_update() {
    foreach (eqLogic::byType('remi') as $eqLogic) {
        $eqLogic->save(); // (re)crée les commandes manquantes
    }
}

function remi_remove() {
}
