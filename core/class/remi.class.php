<?php
require_once dirname(__FILE__) . '/remiCmd.class.php';
require_once dirname(__FILE__) . '/../api/urbanhello_api_wrapper.php';

class remi extends eqLogic {

    public static function backupExclude() {
        return ['resources/python_venv'];
    }

    /**
     * Récupère un token de session valide (en cache ou via login si expiré/manquant)
     */
    public static function getValidSessionToken($forceRefresh = false) {
        $sessionToken = config::byKey('sessionToken', 'remi', '');
        
        if ($sessionToken !== '' && !$forceRefresh) {
            return $sessionToken;
        }

        $username = config::byKey('username', 'remi', '');
        $password = config::byKey('password', 'remi', '');
        if ($username === '' || $password === '') {
            log::add('remi', 'warning', 'Identifiants remi non configurés');
            return null;
        }

        log::add('remi', 'info', 'Génération d\'un nouveau token de session Parse...');
        $login = remiApi::login($username, $password);
        if (is_array($login) && isset($login['sessionToken'])) {
            config::save('sessionToken', $login['sessionToken'], 'remi');
            config::save('userId', $login['objectId'] ?? '', 'remi');
            return $login['sessionToken'];
        }

        log::add('remi', 'warning', 'Échec de connexion lors du renouvellement du token.');
        return null;
    }

    public static function syncRemi() {
        log::add('remi', 'debug', 'Lancement de syncRemi()');
        $sessionToken = self::getValidSessionToken();
        $userId = config::byKey('userId', 'remi', '');

        if (!$sessionToken || !$userId) {
            log::add('remi', 'error', 'Impossible de synchroniser : identifiants ou token invalides');
            return;
        }

        $userInfo = remiApi::userInfo($sessionToken, $userId);
        if (!is_array($userInfo) || !isset($userInfo['remis'])) {
            // Tentative de re-login au cas où le token aurait expiré
            $sessionToken = self::getValidSessionToken(true);
            $userId = config::byKey('userId', 'remi', '');
            $userInfo = remiApi::userInfo($sessionToken, $userId);
            if (!is_array($userInfo) || !isset($userInfo['remis'])) {
                log::add('remi', 'error', 'Aucun REMI trouvé dans userInfo');
                return;
            }
        }

        foreach ($userInfo['remis'] as $remi) {
            $remiId = is_array($remi) && isset($remi['objectId']) ? $remi['objectId'] : $remi;
            log::add('remi', 'debug', 'Traitement du REMI ID: ' . $remiId);
            $eq = eqLogic::byLogicalId($remiId, 'remi');
            if (!is_object($eq)) {
                $eq = new remi();
                $eq->setEqType_name('remi');
                $eq->setLogicalId($remiId);
                $eq->setName('REMI ' . $remiId);
                $eq->setIsEnable(1);
                $eq->setIsVisible(1);
                $eq->save();
            }
            $eq->createCommands();
            $eq->updateInfos();
        }
    }

    public function prepareEventPayload($param, $value) {
        $payload = [];
        switch ($param) {
            case 'enabled':
                $payload['enabled'] = ($value == '1' || $value == 'true' || $value == 'on');
                break;
            case 'volume':
                $payload['volume'] = (int)$value;
                break;
            case 'name':
                $payload['name'] = (string)$value;
                break;
            case 'music_path':
                $payload['music_path'] = (string)$value;
                break;
            case 'time':
                $h = null; $m = null;
                if (strpos($value, ':') !== false) {
                    $parts = explode(':', $value);
                    $h = $parts[0]; $m = $parts[1];
                } elseif (strlen($value) == 4 && is_numeric($value)) {
                    $h = substr($value, 0, 2);
                    $m = substr($value, 2, 2);
                }
                if ($h !== null && $m !== null) {
                    $payload['event_time'] = [(int)$h, (int)$m];
                }
                break;
            case 'light':
                $payload['brightness'] = max(0, min(100, (int)$value));
                break;
            case 'recurrence':
                $days = explode(',', $value);
                if (count($days) == 7) {
                    $payload['recurrence'] = array_map('intval', $days);
                }
                break;
            case 'face':
                // Correction du bug sur blankFace
                $faceMap = [
                    'awakeFace'     => 'fIjF0yWRxX',
                    'sleepyFace'    => 'rnAltoFwYC',
                    'semiAwakeFace' => '9faiiPGBVv',
                    'blankFace'     => 'GDaZOVdRqj'
                ];
                if (isset($faceMap[$value])) {
                    $payload['face'] = [
                        '__type' => 'Pointer',
                        'className' => 'Face',
                        'objectId' => $faceMap[$value]
                    ];
                }
                break;
        }
        return $payload;
    }

    public static function cron5() {
        foreach (self::byType('remi', true) as $eq) {
            $eq->updateInfos();
        }
    }

    public function createCommands() {
        $cmds = [
            ['Remi_ID', 'info', 'string', 'Remi ID', []],
            ['Remi_unique_ID', 'info', 'string', 'ID unique du REMI', []],
            ['last_update', 'info', 'string', 'Dernière mise à jour', []],
            ['nom', 'info', 'string', 'Nom', []],
            ['firmware_version', 'info', 'string', 'Version du firmware', []],
            ['firmware_need_update', 'info', 'binary', 'Mise à jour firmware nécessaire', []],
            ['alive', 'info', 'binary', 'Alive', []],
            ['online', 'info', 'binary', 'Online', []],
            ['background_color', 'info', 'string', 'Couleur de fond', []],
            ['IP', 'info', 'string', 'IP', []],
            ['RSSI', 'info', 'numeric', 'RSSI', ['unit' => 'dB']],
            ['temperature', 'info', 'numeric', 'Température', ['unit' => '°C']],
            ['MusicMode', 'info', 'string', 'MusicMode', []],
            ['MusicPath', 'info', 'string', 'MusicPath', []],
            ['play_music', 'action', 'message', 'Démarrer musique', ['template' => '{{message}}']],
            ['stop_music', 'action', 'other', 'Arrêter musique', []],
            ['veilleuse', 'info', 'numeric', 'Luminosité', ['unit' => '%']],
            ['set_veilleuse', 'action', 'slider', 'Régler luminosité', ['min' => 0, 'max' => 100, 'unit' => '%']],
            ['light_min', 'info', 'numeric', 'Lum. Ecran Nuit', ['min' => 0, 'max' => 100, 'unit' => '%']],
            ['set_light_min', 'action', 'slider', 'Régler lum. Ecran Nuit', ['min' => 0, 'max' => 100, 'unit' => '%']],
            ['volume', 'info', 'numeric', 'Volume', ['unit' => '%']],
            ['set_volume', 'action', 'slider', 'Régler volume', ['min' => 0, 'max' => 100, 'unit' => '%']],
            ['face', 'info', 'string', 'Face', []],
            ['Visage_num', 'info', 'numeric', 'Visage (num)', []],
            ['awakeFace', 'action', 'other', 'Visage éveillé', []],
            ['sleepyFace', 'action', 'other', 'Visage endormi', []],
            ['blankFace', 'action', 'other', 'Visage blanc', []],
            ['semiAwakeFace', 'action', 'other', 'Visage semi-ouvert', []],
            ['music_list', 'info', 'string', 'Liste musiques', []],
            ['play_selectmusic', 'action', 'select', 'Jouer une musique', []],
            ['event_list', 'info', 'string', 'Liste des réveils', []],
            ['next_alarm', 'info', 'string', 'Prochaine sonnerie', []],
            ['noise_notification_subscribers', 'info', 'numeric', 'Nb abonnés bruit', []],
            ['refresh', 'action', 'other', 'Rafraîchir', []],
            ['event_enable', 'action', 'select', 'Activer un réveil', []],
            ['event_disable', 'action', 'select', 'Désactiver un réveil', []],
            ['event_set_param', 'action', 'message', 'Modifier un paramètre de reveil', []],
            ['set_face', 'action', 'select', 'Changer de visage', [
                'listValue' => 'awakeFace|Visage éveillé;sleepyFace|Visage endormi;blankFace|Visage blanc;semiAwakeFace|Visage semi-ouvert'
            ]],
        ];

        foreach ($cmds as $c) {
            list($logical, $type, $subtype, $name, $opts) = array_pad($c, 5, []);
            $cmd = $this->getCmd(null, $logical);
            if (!is_object($cmd)) {
                $cmd = new remiCmd();
                $cmd->setName($name);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setLogicalId($logical);
                $cmd->setType($type);
                $cmd->setSubType($subtype);
                if (isset($opts['min'])) $cmd->setConfiguration('minValue', $opts['min']);
                if (isset($opts['max'])) $cmd->setConfiguration('maxValue', $opts['max']);
                if (isset($opts['unit'])) $cmd->setUnite($opts['unit']);
                if (isset($opts['listValue'])) $cmd->setConfiguration('listValue', $opts['listValue']);
                if ($subtype == 'message' && isset($opts['template'])) $cmd->setConfiguration('template', $opts['template']);
                $cmd->save();
            }
        }
    }

    private function updateAlarmActionLists(array $alarms) {
        $listValues = [];
        foreach ($alarms as $alarm) {
            if (empty($alarm['objectId']) || empty($alarm['event_time'])) continue;
            $hour = str_pad($alarm['event_time'][0], 2, '0', STR_PAD_LEFT);
            $minute = str_pad($alarm['event_time'][1], 2, '0', STR_PAD_LEFT);
            $time = $hour . ':' . $minute;
            $name = trim($alarm['name'] ?? 'Réveil');
            $listValues[] = $alarm['objectId'] . '|' . $time . ' - ' . $name;
        }

        $listStr = implode(';', $listValues);
        foreach (['event_enable', 'event_disable'] as $logical) {
            $cmd = $this->getCmd(null, $logical);
            if (is_object($cmd)) {
                if ($cmd->getConfiguration('listValue') !== $listStr) {
                    $cmd->setConfiguration('listValue', $listStr);
                    $cmd->save();
                }
            }
        }
    }

    /** Liste "valeur|libellé" des sons pour les commandes Sonnerie (null = inconnue, on ne touche pas). */
    private $alarmSoundList = null;

    /**
     * Valeur attendue par le champ music_path d'un réveil, d'après le format de l'appli Rémi :
     * - son du Store  : path "../.FreePublisher" -> "../.FreePublisher/01 - Le Corbeau et le Renard.mp3"
     * - son perso     : path ""                  -> "Le_héros_du_préau.mp3"
     */
    public static function alarmSoundValue($music) {
        if (is_array($music)) {
            $name = $music['name'] ?? '';
            $path = rtrim((string)($music['path'] ?? ''), '/\\');
        } else {
            $name = (string)$music;
            $path = '';
        }
        return $path !== '' ? $path . '/' . $name : $name;
    }

    public static function buildAlarmSoundList($musics) {
        $items = [];
        foreach ((array)$musics as $music) {
            $name = is_array($music) ? ($music['name'] ?? '') : (string)$music;
            if ($name === '' || strpbrk($name, '|;') !== false) continue; // séparateurs de liste Jeedom
            $items[] = self::alarmSoundValue($music) . '|' . pathinfo($name, PATHINFO_FILENAME);
        }
        return implode(';', $items);
    }

    private function syncAlarms(array $alarms) {
        $currentAlarmIds = [];
        foreach ($alarms as $alarm) {
            if (!empty($alarm['objectId'])) {
                $currentAlarmIds[] = $alarm['objectId'];
            }
        }

        foreach ($this->getCmd() as $cmd) {
            foreach (['alarm_', 'sound_', 'soundinfo_'] as $prefix) {
                if (strpos($cmd->getLogicalId(), $prefix) === 0) {
                    $alarmId = substr($cmd->getLogicalId(), strlen($prefix));
                    if (!in_array($alarmId, $currentAlarmIds)) {
                        $cmd->remove();
                    }
                }
            }
        }

        // noms en double (ex. plusieurs réveils sans nom) : la commande Sonnerie reçoit aussi l'heure
        $nameCount = [];
        foreach ($alarms as $alarm) {
            $n = mb_strtolower(trim($alarm['name'] ?? '') ?: 'Réveil');
            $nameCount[$n] = ($nameCount[$n] ?? 0) + 1;
        }

        foreach ($alarms as $alarm) {
            if (empty($alarm['objectId']) || empty($alarm['event_time'])) continue;

            $hour = str_pad($alarm['event_time'][0], 2, '0', STR_PAD_LEFT);
            $minute = str_pad($alarm['event_time'][1], 2, '0', STR_PAD_LEFT);
            $time = $hour . ':' . $minute;
            $name = trim($alarm['name'] ?? '') ?: 'Réveil';
            $logicalId = 'alarm_' . $alarm['objectId'];

            $cmdName = $time . ' – ' . $name . ' (' . self::formatDays($alarm['recurrence'] ?? []) . ')';
            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                $cmd = new remiCmd();
                $cmd->setEqLogic_id($this->getId());
                $cmd->setLogicalId($logicalId);
                $cmd->setName($cmdName);
                $cmd->setType('info');
                $cmd->setSubType('binary');
                $cmd->setIsVisible(1);
                $cmd->setIsHistorized(0);
                $cmd->save();
            } elseif ($cmd->getName() !== $cmdName) {
                // l'heure ou le nom du réveil a changé (appli Rémi ou plugin Réveils) : on met le nom à jour
                $cmd->setName($cmdName);
                $cmd->save();
            }

            $state = !empty($alarm['enabled']) ? 1 : 0;
            $this->checkAndUpdateCmd($logicalId, $state);

            // commande action "Sonnerie – <nom>" : liste des musiques, envoyée sur ce réveil
            $soundLogical = 'sound_' . $alarm['objectId'];
            $soundName = 'Sonnerie – ' . $name . (($nameCount[mb_strtolower($name)] ?? 1) > 1 ? ' ' . $time : '');
            $scmd = $this->getCmd(null, $soundLogical);
            $changed = false;
            if (!is_object($scmd)) {
                $scmd = new remiCmd();
                $scmd->setEqLogic_id($this->getId());
                $scmd->setLogicalId($soundLogical);
                $scmd->setType('action');
                $scmd->setSubType('select');
                $scmd->setIsVisible(1);
                $changed = true;
            }
            if ($scmd->getName() !== $soundName) {
                $scmd->setName($soundName);
                $changed = true;
            }
            if ($this->alarmSoundList !== null && $scmd->getConfiguration('listValue') !== $this->alarmSoundList) {
                $scmd->setConfiguration('listValue', $this->alarmSoundList);
                $changed = true;
            }
            if ($changed) {
                $scmd->save();
            }

            // info "Sonnerie actuelle – <nom>" : son enregistré sur ce réveil, en clair
            $infoLogical = 'soundinfo_' . $alarm['objectId'];
            $infoName = 'Sonnerie actuelle – ' . $name . (($nameCount[mb_strtolower($name)] ?? 1) > 1 ? ' ' . $time : '');
            $icmd = $this->getCmd(null, $infoLogical);
            if (!is_object($icmd)) {
                $icmd = new remiCmd();
                $icmd->setEqLogic_id($this->getId());
                $icmd->setLogicalId($infoLogical);
                $icmd->setName($infoName);
                $icmd->setType('info');
                $icmd->setSubType('string');
                $icmd->setIsVisible(1);
                $icmd->setIsHistorized(0);
                $icmd->save();
            } elseif ($icmd->getName() !== $infoName) {
                $icmd->setName($infoName);
                $icmd->save();
            }
            $this->checkAndUpdateCmd($infoLogical, $this->soundLabel($alarm['music_path'] ?? ''));
        }
    }

    /** Libellé lisible du son d'un réveil ; ⚠ si le chemin ne correspond à aucune musique du Rémi. */
    private function soundLabel($musicPath) {
        $musicPath = (string)$musicPath;
        if ($musicPath === '') {
            return 'Aucune';
        }
        $label = pathinfo($musicPath, PATHINFO_FILENAME);
        if ($this->alarmSoundList !== null) {
            $known = [];
            foreach (explode(';', $this->alarmSoundList) as $item) {
                $known[] = explode('|', $item)[0];
            }
            if (!in_array($musicPath, $known, true)) {
                return '⚠ ' . $label . ' (introuvable sur le Rémi)';
            }
        }
        return $label;
    }

    /**
     * Jours d'un réveil à partir de recurrence[7] (ordre lundi -> dimanche).
     * [1,1,1,1,1,0,0] -> "lun-ven", [0,0,0,1,0,0,0] -> "jeu", [1,0,1,0,0,0,0] -> "lun,mer"
     */
    public static function formatDays($recurrence) {
        $names = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];
        $on = [];
        foreach (array_values((array)$recurrence) as $i => $v) {
            if ($i < 7 && (int)$v === 1) $on[] = $i;
        }
        if (count($on) == 0) return 'une fois';
        if (count($on) == 7) return 'tous les jours';
        // regroupement des jours consécutifs (3 ou plus) en plage
        $parts = [];
        $start = $prev = $on[0];
        foreach (array_merge(array_slice($on, 1), [null]) as $d) {
            if ($d !== null && $d == $prev + 1) { $prev = $d; continue; }
            if ($prev - $start >= 2) {
                $parts[] = $names[$start] . '-' . $names[$prev];
            } else {
                for ($k = $start; $k <= $prev; $k++) $parts[] = $names[$k];
            }
            if ($d !== null) { $start = $prev = $d; }
        }
        return implode(',', $parts);
    }

    /**
     * Prochaine sonnerie d'un réveil actif (timestamp), ou null.
     * Sans jour coché, le Rémi sonne une seule fois à la prochaine occurrence de l'heure.
     */
    public static function nextRing($alarm, $now = null) {
        if (empty($alarm['enabled']) || !isset($alarm['event_time'][0], $alarm['event_time'][1])) return null;
        $now = $now ?? time();
        $rec = array_values((array)($alarm['recurrence'] ?? []));
        $once = array_sum(array_map('intval', $rec)) == 0;
        for ($offset = 0; $offset <= 7; $offset++) {
            $day = strtotime(date('Y-m-d', $now) . ' +' . $offset . ' day');
            $ts = mktime((int)$alarm['event_time'][0], (int)$alarm['event_time'][1], 0, (int)date('n', $day), (int)date('j', $day), (int)date('Y', $day));
            if ($ts <= $now) continue;
            $isoIndex = (int)date('N', $ts) - 1; // 0 = lundi
            if ($once || (isset($rec[$isoIndex]) && (int)$rec[$isoIndex] === 1)) return $ts;
        }
        return null;
    }

    /** Remplit "Liste des réveils" et "Prochaine sonnerie". */
    private function updateAlarmSummary(array $alarms) {
        if (!is_object($this->getCmd(null, 'next_alarm'))) {
            $this->createCommands(); // équipement existant : crée la nouvelle info
        }
        $lines = [];
        $next = null;
        foreach ($alarms as $alarm) {
            if (empty($alarm['event_time'])) continue;
            $time = sprintf('%02d:%02d', $alarm['event_time'][0], $alarm['event_time'][1]);
            $name = trim($alarm['name'] ?? '') ?: 'Réveil';
            $sound = !empty($alarm['music_path']) ? ' ♪ ' . pathinfo($alarm['music_path'], PATHINFO_FILENAME) : '';
            $lines[] = $name . ' : ' . $time . ' ' . self::formatDays($alarm['recurrence'] ?? []) . (!empty($alarm['enabled']) ? '' : ' (inactif)') . $sound;
            $ts = self::nextRing($alarm);
            if ($ts !== null && ($next === null || $ts < $next)) $next = $ts;
        }
        $this->checkAndUpdateCmd('event_list', implode(' | ', $lines));
        // format AAAA-MM-JJ HH:MM, directement exploitable dans les scénarios
        $this->checkAndUpdateCmd('next_alarm', $next !== null ? date('Y-m-d H:i', $next) : 'Aucune');
    }

    public function updateInfos() {
        $id = $this->getLogicalId();
        $token = self::getValidSessionToken();

        if (!$token) {
            $this->handleSyncError('Identifiants manquants ou session impossible');
            return;
        }

        $info = remiApi::remiInfo($token, $id);

        // Si la requête échoue, on tente un renouvellement de token (au cas où il ait expiré)
        if (!is_array($info)) {
            $token = self::getValidSessionToken(true);
            if ($token) {
                $info = remiApi::remiInfo($token, $id);
            }
        }

        if (!is_array($info)) {
            $this->handleSyncError('Impossible de joindre le REMI ' . $id);
            return;
        }

        // Succès de la synchronisation : réinitialisation du compteur d'erreurs
        $this->handleSyncSuccess();

        // 1. Musiques
        $musics = remiApi::listMusics($token, $id);
        if (is_array($musics) && count($musics) > 0) {
            $listValue = [];
            foreach ($musics as $music) {
                if (is_array($music) && isset($music['name'])) {
                    $fileName = $music['name'];
                    $filePath = !empty($music['path']) ? $music['path'] . '\\' : '';
                    $listValue[] = ($filePath . $fileName) . '|' . pathinfo($fileName, PATHINFO_FILENAME);
                } else {
                    $listValue[] = $music . '|' . pathinfo($music, PATHINFO_FILENAME);
                }
            }
            $selectCmd = $this->getCmd(null, 'play_selectmusic');
            if (is_object($selectCmd)) {
                $selectCmd->setConfiguration('listValue', implode(';', $listValue));
                $selectCmd->save();
            }
            $this->checkAndUpdateCmd('music_list', json_encode($musics, JSON_UNESCAPED_UNICODE));
            $this->alarmSoundList = self::buildAlarmSoundList($musics);
        }

        // 2. Alarmes
        $alarms = remiApi::listAlarms($token, $id);
        if (is_array($alarms)) {
            $filteredAlarms = array_filter($alarms, function($alarm) use ($id) {
                return isset($alarm['objectId'], $alarm['remi']['objectId']) && $alarm['remi']['objectId'] === $id;
            });
            $this->syncAlarms($filteredAlarms);
            $this->updateAlarmActionLists($filteredAlarms);
            $this->updateAlarmSummary($filteredAlarms);
        }

        // 3. Couleurs
        $colorMap = [
            '62,177,200' => 'blue',
            '255,115,120' => 'pink',
            '254,219,0' => 'yellow',
            '205,205,205' => 'grey'
        ];
        $bgColor = '205,205,205';
        if (isset($info['background_color']) && is_array($info['background_color'])) {
            $bgColor = implode(',', $info['background_color']);
        }
        $backgroundColor = $colorMap[$bgColor] ?? 'blue';

        // 4. Face (déduit directement de $info sans faire de requête HTTP en plus)
        $faceMapInv = [
            'rnAltoFwYC' => 'sleepyFace',
            'fIjF0yWRxX' => 'awakeFace',
            'GDaZOVdRqj' => 'blankFace',
            '9faiiPGBVv' => 'semiAwakeFace'
        ];
        $faceNumMap = [
            'awakeFace' => 1,
            'sleepyFace' => 2,
            'semiAwakeFace' => 3,
            'blankFace' => 4
        ];
        $faceId = $info['face']['objectId'] ?? null;
        $currentFace = $faceMapInv[$faceId] ?? 'awakeFace';

        // 5. Mise à jour optimisée via checkAndUpdateCmd
        $this->checkAndUpdateCmd('veilleuse', $info['luminosity'] ?? 0);
        $this->checkAndUpdateCmd('Visage_num', $faceNumMap[$currentFace] ?? 0);
        $this->checkAndUpdateCmd('face', $currentFace);
        $this->checkAndUpdateCmd('temperature', round(($info['temp'] ?? 0) * 0.128, 1));
        $this->checkAndUpdateCmd('light_min', round(($info['light_min'] ?? 0) * 10));
        $this->checkAndUpdateCmd('volume', $info['volume'] ?? 0);
        $this->checkAndUpdateCmd('nom', $info['name'] ?? '');
        $this->checkAndUpdateCmd('online', !empty($info['online']) ? 1 : 0);
        $this->checkAndUpdateCmd('alive', !empty($info['alive']) ? 1 : 0);
        $this->checkAndUpdateCmd('IP', $info['ipv4Address'] ?? '');
        $this->checkAndUpdateCmd('RSSI', $info['rssi'] ?? 0);
        $this->checkAndUpdateCmd('MusicPath', $info['musicPath'] ?? '');
        $this->checkAndUpdateCmd('MusicMode', $info['musicMode'] ?? '');
        $this->checkAndUpdateCmd('background_color', $backgroundColor);
        $this->checkAndUpdateCmd('firmware_version', $info['update_firmware_version'] ?? '');
        $this->checkAndUpdateCmd('firmware_need_update', !empty($info['firmware_need_update']) ? 1 : 0);
        $this->checkAndUpdateCmd('Remi_unique_ID', $info['uniqueID'] ?? '');
        $this->checkAndUpdateCmd('Remi_ID', $id);
        $this->checkAndUpdateCmd('last_update', date('d-m-Y H:i:s'));

        if (isset($info['noise_notification_subscribers']) && is_array($info['noise_notification_subscribers'])) {
            $this->checkAndUpdateCmd('noise_notification_subscribers', count($info['noise_notification_subscribers']));
        }
    }

    /**
     * Gestion du seuil des 3 erreurs consécutives
     */
    private function handleSyncError($errorMsg) {
        $errors = (int)$this->getCache('consecutive_errors', 0) + 1;
        $this->setCache('consecutive_errors', $errors);

        if ($errors >= 3) {
            log::add('remi', 'error', sprintf('Échec persistant de communication avec REMI (%d tentatives) : %s', $errors, $errorMsg));
        } else {
            log::add('remi', 'warning', sprintf('Échec temporaire (%d/3) avec REMI : %s', $errors, $errorMsg));
        }
    }

    private function handleSyncSuccess() {
        if ((int)$this->getCache('consecutive_errors', 0) > 0) {
            log::add('remi', 'info', 'Connexion rétablie avec le réveil REMI.');
            $this->setCache('consecutive_errors', 0);
        }
    }
}