<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    require_once dirname(__FILE__) . '/../class/remi.class.php';
    require_once dirname(__FILE__) . '/../api/urbanhello_api_wrapper.php';

    $action = init('action');

    switch ($action) {

        /*
         * Test des identifiants UrbanHello.
         * Utilise les valeurs saisies dans la page de configuration,
         * ou à défaut celles déjà sauvegardées.
         */
        case 'testLogin':
            $username = trim(init('username'));
            $password = init('password');
            if ($username === '') {
                $username = config::byKey('username', 'remi', '');
            }
            if ($password === '') {
                $password = config::byKey('password', 'remi', '');
            }
            if ($username === '' || $password === '') {
                throw new Exception(__('Identifiant ou mot de passe non renseigné', __FILE__));
            }

            $res = remiApi::login($username, $password);
            if ($res === false) {
                throw new Exception(__('Erreur d\'appel à l\'API UrbanHello (voir les logs remi)', __FILE__));
            }
            if (!is_array($res) || !isset($res['sessionToken'])) {
                $msg = is_string($res) ? $res : json_encode($res);
                throw new Exception(__('Connexion refusée par UrbanHello : ', __FILE__) . $msg);
            }

            // On garde le token ET l'identifiant utilisateur : syncRemi() a besoin des deux
            config::save('sessionToken', $res['sessionToken'], 'remi');
            config::save('userId', $res['objectId'] ?? '', 'remi');
            log::add('remi', 'info', 'Test de connexion réussi');
            ajax::success(array('status' => 'ok'));
            break;

        /*
         * Liste brute des REMI rattachés au compte
         */
        case 'listRemi':
            $sessionToken = remi::getValidSessionToken();
            $userId = config::byKey('userId', 'remi', '');
            if (!$sessionToken || $userId === '') {
                throw new Exception(__('Connexion impossible : vérifiez les identifiants dans la configuration du plugin', __FILE__));
            }
            $userInfo = remiApi::userInfo($sessionToken, $userId);
            if (!is_array($userInfo)) {
                // Token probablement expiré : on force un nouveau login
                $sessionToken = remi::getValidSessionToken(true);
                $userId = config::byKey('userId', 'remi', '');
                $userInfo = remiApi::userInfo($sessionToken, $userId);
            }
            if (!is_array($userInfo)) {
                throw new Exception(__('Impossible de récupérer les informations du compte', __FILE__));
            }
            ajax::success(array(
                'remis' => isset($userInfo['remis']) ? $userInfo['remis'] : array(),
                'user'  => $userInfo
            ));
            break;

        /*
         * Création / mise à jour des équipements REMI
         */
        case 'syncRemi':
            if (config::byKey('username', 'remi', '') === '' || config::byKey('password', 'remi', '') === '') {
                throw new Exception(__('Renseignez et sauvegardez vos identifiants avant de synchroniser', __FILE__));
            }
            log::add('remi', 'debug', 'Début de la synchronisation des REMI');
            remi::syncRemi();
            $nb = count(eqLogic::byType('remi'));
            if ($nb === 0) {
                throw new Exception(__('Aucun REMI synchronisé (voir les logs remi)', __FILE__));
            }
            ajax::success(array('count' => $nb));
            break;

        /*
         * Informations détaillées d'un REMI
         */
        case 'remiInfo':
            $remiId = init('remiId');
            if ($remiId === '') {
                throw new Exception(__('Identifiant REMI manquant', __FILE__));
            }
            $token = remi::getValidSessionToken();
            if (!$token) {
                throw new Exception(__('Plugin non configuré ou connexion impossible', __FILE__));
            }
            $info = remiApi::remiInfo($token, $remiId);
            if (!is_array($info)) {
                $token = remi::getValidSessionToken(true);
                $info = remiApi::remiInfo($token, $remiId);
            }
            if (!is_array($info)) {
                throw new Exception(__('Impossible de récupérer les informations du REMI', __FILE__));
            }
            ajax::success($info);
            break;

        default:
            throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . $action);
    }

} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
