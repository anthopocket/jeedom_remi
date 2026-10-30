<?php
require_once dirname(__FILE__) . '/../api/urbanhello_api_wrapper.php';

class remiCmd extends cmd {

    public function execute($_options = array()) {
        try {
            $eq = $this->getEqLogic();
            if (!is_object($eq)) {
                throw new Exception('Équipement introuvable');
            }

            $remiId = $eq->getLogicalId();
            // Utilisation du token existant
            $token = remi::getValidSessionToken();
            if (!$token) {
                throw new Exception('Impossible d\'obtenir un token API valide');
            }

            $logical = $this->getLogicalId();

            // "Sonnerie – <réveil>" : change le son de ce réveil
            if (strpos($logical, 'sound_') === 0) {
                if (empty($_options['select'])) {
                    throw new Exception('Aucune sonnerie sélectionnée');
                }
                $alarmId = substr($logical, strlen('sound_'));
                $result = remiApi::updateEvent($token, $alarmId, ['music_path' => (string)$_options['select']]);
                $eq->updateInfos();
                return $result;
            }

            switch ($logical) {
                case 'set_veilleuse':
                    $value = intval($_options['slider']);
                    $result = remiApi::setLuminosity($token, $remiId, $value);
                    $cmd = $eq->getCmd(null, 'veilleuse');
                    if (is_object($cmd)) {
                        $cmd->event($value);
                    }
                    return $result;
                
              	case 'set_light_min':
                	$sourcevalue = intval($_options['slider']);
                	$value = (int)round(($sourcevalue / 100) * 10);
                    $result = remiApi::setNightLuminosity($token, $remiId, $value);
                    $cmd = $eq->getCmd(null, 'light_min');
                    if (is_object($cmd)) {
                        $cmd->event($value*10);
                    }
                    return $result;
                

                case 'set_volume':
                    $value = intval($_options['slider']);
                    $result = remiApi::setVolume($token, $remiId, $value);
                    $cmd = $eq->getCmd(null, 'volume');
                    if (is_object($cmd)) {
                        $cmd->event($value);
                    }
                    return $result;

                case 'awakeFace':
                case 'sleepyFace':
                case 'semiAwakeFace':
                case 'blankFace':
                    $result = remiApi::setFace($token, $remiId, $logical);
                    $faceCmd = $eq->getCmd(null, 'face');
                    $visageNumCmd = $eq->getCmd(null, 'Visage_num');
                    if (is_object($faceCmd)) {
                        $faceCmd->event($logical);
                    }
                    if (is_object($visageNumCmd)) {
                        $faceMap = [
                            'awakeFace' => 1,
                            'sleepyFace' => 2,
                            'semiAwakeFace' => 3,
                            'blankFace' => 4
                        ];
                        $visageNumCmd->event($faceMap[$logical]);
                    }
                    return $result;

                case 'play_music':
                    $msg = '';
                    if (is_array($_options) && isset($_options['message'])) {
                        $msg = $_options['message'];
                    }
                    return remiApi::playMusic($token, $remiId, $msg);

                case 'stop_music':
                    return remiApi::stopMusic($token, $remiId);

		case 'play_selectmusic':
		    if (!isset($_options['select'])) {
			throw new Exception('Aucune musique sélectionnée');
	            }
		    return remiApi::playMusic($token, $remiId, $_options['select']);

		case 'event_enable':
		case 'event_disable':
		    if (!isset($_options['select'])) {
			throw new Exception('Aucun réveil sélectionné');
		    }
		    $enabled = ($logical === 'event_enable');
		    $alarmId = $_options['select'];
		    $result = remiApi::setAlarmEnabled($token, $alarmId, $enabled);
		    $eq = $this->getEqLogic();
		    if (is_object($eq)) {
			$eq->updateInfos();
		    }
         	    return $result;

		case 'set_face':
		    if (!isset($_options['select'])) {
			throw new Exception('Aucun visage sélectionné');
		    }
		    $face = $_options['select'];
		    $result = remiApi::setFace($token, $remiId, $face);

                    $eq = $this->getEqLogic();
		    if (is_object($eq)) {
 			$faceCmd = $eq->getCmd(null, 'face');
			if (is_object($faceCmd)) {
				$faceCmd->event($face);
			}

	         	$visageNumCmd = $eq->getCmd(null, 'Visage_num');
		        if (is_object($visageNumCmd)) {
				$faceMap = [
				'awakeFace' => 1,
				'sleepyFace' => 2,
				'semiAwakeFace' => 3,
				'blankFace' => 4
				];
				$visageNumCmd->event($faceMap[$face] ?? 0);
		    	}
		    }
		    return $result;


	 	case 'event_set_param':
                    $title = trim($_options['title']);
                    $message = $_options['message'];
                    // titre = identifiant du réveil (alarm_xxx ou xxx) OU son nom (ex. "reveik")
                    $alarmId = self::resolveAlarmId($eq, $title);
                    if ($alarmId === null) {
                        log::add('remi', 'error', 'Réveil introuvable : "' . $title . '" (identifiant ou nom)');
                        return false;
                    }

                    $parts = explode(';', $message);
                    if (count($parts) < 2) {
                        log::add('remi', 'error', 'Format message invalide pour event_set_param. Attendu: clé;valeur');
                        return false;
                    }

                    $key = trim($parts[0]);
                    $val = trim($parts[1]);

                    $payload = $this->getEqLogic()->prepareEventPayload($key, $val);

                    if (empty($payload)) {
                        log::add('remi', 'error', 'Paramètre inconnu ou valeur mal formée : ' . $key);
                        return false;
                    }

                    $result = remiApi::updateEvent($token, $alarmId, $payload);
//                    $this->getEqLogic()->refresh();

                    $eq = $this->getEqLogic();
                    if (is_object($eq)) {
                        $eq->updateInfos();
                    }

                    return $result;

                case 'refresh':
                    $eq->updateInfos();
                    return true;

                default:
                    throw new Exception('Commande non gérée: ' . $logical);
            }
        } catch (Exception $e) {
            log::add('remi', 'error', 'Erreur lors de l\'exécution de la commande: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Retrouve l'identifiant d'un réveil à partir de "alarm_xxx", "xxx" ou de son nom.
     * Le nom est lu dans les commandes "HH:MM – <nom> (<jours>)".
     */
    public static function resolveAlarmId($eq, $title) {
        $id = str_replace('alarm_', '', $title);
        if (is_object($eq->getCmd(null, 'alarm_' . $id))) {
            return $id;
        }
        $wanted = mb_strtolower(trim($title));
        foreach ($eq->getCmd('info') as $cmd) {
            if (strpos($cmd->getLogicalId(), 'alarm_') !== 0) continue;
            if (preg_match('/^\d{1,2}:\d{2}\s*[–-]\s*(.*?)(?:\s*\([^()]*\))?\s*$/u', $cmd->getName(), $m)
                && mb_strtolower(trim($m[1])) === $wanted) {
                return substr($cmd->getLogicalId(), strlen('alarm_'));
            }
        }
        // identifiant inconnu des commandes (réveil créé depuis le dernier rafraîchissement) : on le tente tel quel
        return preg_match('/^[A-Za-z0-9]{10}$/', $id) ? $id : null;
    }
}