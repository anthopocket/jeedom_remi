<?php
try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

    // Re-open session when PHPSESSID provided (fix Jeedom 4.3/4.4 edge cases)
    if (isset($_REQUEST['PHPSESSID'])) {
        session_write_close();
        session_id($_REQUEST['PHPSESSID']);
        session_start();
    }

    if (!isConnect()) {
        throw new Exception('401 - Accès non autorisé');
    }

    require_once dirname(__FILE__) . '/../class/remi.class.php';
    require_once dirname(__FILE__) . '/../api/urbanhello_api_wrapper.php';

    $action = init('action');

    switch ($action) {
case 'testLogin':
        $username = init('username');
        $password = init('password');
        $res = remiApi::login($username, $password);
        if ($res === false) {
            ajax::error('Erreur appel API (voir logs).');
        }
        if (!is_array($res) || !isset($res['sessionToken'])) {
            $msg = is_string($res) ? $res : json_encode($res);
            ajax::error('Login API échoué : ' . $msg);
        }
        config::save('sessionToken', $res['sessionToken'], 'remi');
        ajax::success(array('status' => 'ok', 'user' => $res));
        break;

        case 'listRemi':
            $sessionToken = config::byKey('sessionToken', 'remi', '');
            if ($sessionToken == '') {
                ajax::error('Token de session non disponible');
            }
            $userId = config::byKey('userId', 'remi', '');
            if ($userId == '') {
                ajax::error('ID utilisateur non disponible');
            }
            $userInfo = remiApi::userInfo($sessionToken, $userId);
            if (!is_array($userInfo)) {
                ajax::error('Impossible de récupérer userInfo');
            }
            ajax::success(array('remis' => isset($userInfo['remis']) ? $userInfo['remis'] : array(), 'user' => $userInfo));
            break;


        case 'syncRemi':
            log::add('remi', 'debug', 'Début de la synchronisation des REMI');
            remi::syncRemi();
            ajax::success('OK');
            break;


        case 'remiInfo':
            $remiId = init('remiId');
            $username = config::byKey('username','remi','');
            $password = config::byKey('password','remi','');
            if ($username=='' || $password=='') { ajax::error('Plugin non configuré'); }
            $login = remiApi::login($username,$password);
            if (!is_array($login) || !isset($login['sessionToken'])) { ajax::error('Login API échoué'); }
            $token = $login['sessionToken'];
            $info = remiApi::remiInfo($token, $remiId);
            ajax::success($info);
            break;
        
        
        case 'dependancy_install':
            log::add('remi', 'info', 'Installation des dépendances lancée');

            // on demande à Jeedom de lancer les dépendances
            jeedom::setProgressBar(0);

            // lance réellement l’installation
            $cmd = 'sudo /bin/bash ' . dirname(__FILE__) . '/../../resources/install_apt.sh';
            $return = system($cmd . ' >> ' . log::getPathToLog('remi_dep') . ' 2>&1');

            plugin::byId('remi')->setState('dependencies', 'ok');
            plugin::byId('remi')->save();

            ajax::success();
            break;

        default:
            ajax::error("Action inconnue : $action");
    }

} catch (Exception $e) {
    ajax::error($e->getMessage());
}