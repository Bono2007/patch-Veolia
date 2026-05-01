    /* @VEOLIA_TR_PATCH_V1@ */
    /**
     * Récupère la consommation pour les compteurs TR de toutsurmoneau.fr (SUEZ)
     * via la nouvelle API JSON (juin 2026+). Court-circuite le scraping HTML/CSV
     * et PHPExcel devenus inutiles.
     */
    public function getConsoToutsurmoneauTR($mock_test) {
        $login    = $this->getConfiguration('login');
        $password = $this->getConfiguration('password');
        $url_site = 'www.toutsurmoneau.fr';
        $base     = 'https://'.$url_site;

        if (empty($login) || empty($password)) {
            log::add('veolia_eau', 'error', 'Identifiants non saisis');
            return -1;
        }

        $cookie_file = sys_get_temp_dir().'/veolia_php_cookies_'.uniqid();
        static::secure_touch($cookie_file);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
        curl_setopt($ch, CURLOPT_COOKIEJAR,  $cookie_file);
        curl_setopt($ch, CURLOPT_USERAGENT,
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '.
            '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');

        // 1. GET la page de login pour récupérer le cookie de session + CSRF
        log::add('veolia_eau', 'debug', '### TR: GET login page ###');
        curl_setopt($ch, CURLOPT_URL, $base.'/mon-compte-en-ligne/je-me-connecte');
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        $loginHtml = curl_exec($ch);
        if ($loginHtml === false) {
            log::add('veolia_eau', 'error', 'TR: échec GET login: '.curl_error($ch));
            curl_close($ch); @unlink($cookie_file); return -1;
        }

        // Le csrfToken est embarqué dans le HTML, escapé en Unicode :
        // ..."csrfToken":"<TOKEN>"...   (échappé en "/: dans la source)
        if (!preg_match('/csrfToken\\\\u0022\\\\u003A\\\\u0022([^\\\\]+)\\\\u0022/', $loginHtml, $mt)) {
            log::add('veolia_eau', 'error', 'TR: csrfToken introuvable dans la page de login');
            curl_close($ch); @unlink($cookie_file); return -1;
        }
        $csrf = $mt[1];
        log::add('veolia_eau', 'debug', 'TR: csrfToken=' . substr($csrf, 0, 16) . '...');

        // 2. POST le login (form Symfony classique)
        log::add('veolia_eau', 'debug', '### TR: POST login ###');
        $postFields = http_build_query(array(
            'tsme_user_login[_username]' => $login,
            'tsme_user_login[_password]' => $password,
            '_csrf_token'                => $csrf,
            'tsme_user_login[_target_path]' => '',
        ));
        curl_setopt($ch, CURLOPT_URL, $base.'/mon-compte-en-ligne/je-me-connecte');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Origin: '.$base,
            'Referer: '.$base.'/mon-compte-en-ligne/je-me-connecte',
        ));
        $loginResponse = curl_exec($ch);
        $loginUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        log::add('veolia_eau', 'debug', 'TR: après login URL=' . $loginUrl);

        // 3. GET la liste des compteurs (API JSON, header XHR pour avoir du 200/JSON)
        $jsonHeaders = array(
            'Accept: application/json, text/plain, */*',
            'X-Requested-With: XMLHttpRequest',
            'Referer: '.$base.'/mon-compte-en-ligne/historique-de-consommation-tr',
        );
        log::add('veolia_eau', 'debug', '### TR: GET meters-list ###');
        curl_setopt($ch, CURLOPT_URL, $base.'/public-api/cel-consumption/meters-list');
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $jsonHeaders);
        $metersRaw = curl_exec($ch);
        $meters = json_decode($metersRaw, true);

        if (!is_array($meters) || empty($meters['content']['clientCompteursPro'][0]['compteursPro'][0]['idPDS'])) {
            log::add('veolia_eau', 'error', 'TR: meters-list vide ou login échoué. Réponse: '.substr($metersRaw, 0, 200));
            curl_close($ch); @unlink($cookie_file); return -1;
        }
        $idPDS = $meters['content']['clientCompteursPro'][0]['compteursPro'][0]['idPDS'];
        log::add('veolia_eau', 'debug', 'TR: idPDS='.$idPDS);

        // 4. GET les mesures quotidiennes
        // Plage: depuis (last config - 2 jours) jusqu'à aujourd'hui, par tranches de 30 jours max
        $lastdate = $this->getConfiguration('last');
        $startTs = $lastdate ? strtotime($lastdate.' -2 days') : strtotime('-60 days');
        if ($startTs > time() || $startTs < strtotime('-3 years')) {
            $startTs = strtotime('-60 days');
        }
        $endTs = time();

        $allMeasures = array();
        $cursorTs = $startTs;
        while ($cursorTs <= $endTs) {
            $chunkEndTs = min($cursorTs + 29 * 86400, $endTs);
            $sd = date('Y-m-d', $cursorTs);
            $ed = date('Y-m-d', $chunkEndTs);
            $url = $base.'/public-api/cel-consumption/telemetry?id_PDS='.$idPDS.
                   '&mode=daily&start_date='.$sd.'&end_date='.$ed;
            log::add('veolia_eau', 'debug', '### TR: GET telemetry '.$sd.' → '.$ed.' ###');
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $jsonHeaders);
            $tRaw = curl_exec($ch);
            $t = json_decode($tRaw, true);
            if (is_array($t) && !empty($t['content']['measures'])) {
                foreach ($t['content']['measures'] as $m) {
                    $allMeasures[] = $m;
                }
            } else {
                log::add('veolia_eau', 'debug', 'TR: chunk vide ou erreur: '.substr($tRaw, 0, 150));
            }
            $cursorTs = $chunkEndTs + 86400;
        }

        curl_close($ch);
        @unlink($cookie_file);

        if (empty($allMeasures)) {
            log::add('veolia_eau', 'error', 'Aucune donnée, merci de vérifier que vos identifiants sont corrects et que vous avez accès au télérelevé de : Tout sur mon eau / Eau en ligne (https://www.toutsurmoneau.fr).');
            return -1;
        }

        // 5. Conversion vers le format interne du plugin
        $datasFetched = array();
        $lastIndex = 0;
        $lastDate = '';
        $consomonth = array();
        foreach ($allMeasures as $m) {
            if (!isset($m['index']) || $m['index'] === null) continue;
            $date  = substr($m['date'], 0, 10); // "YYYY-MM-DD"
            $index = (float) $m['index'];       // m³ cumulés
            $conso = (float) $m['volume'] * 1000; // m³ → litres pour rester cohérent avec processCSV()
            $datasFetched[] = array(
                'date'       => $date,
                'index'      => $index,
                'conso'      => $conso,
                'typeReleve' => 'M',
            );
            $consomonth[] = $conso;
            $lastIndex = $index;
            $lastDate  = $date;
        }
        log::add('veolia_eau', 'info', 'TR: '.count($datasFetched).' mesures récupérées (dernière: '.$lastDate.', index '.$lastIndex.' m³)');

        // 6. Push vers les commandes Jeedom
        foreach ($datasFetched as $data) {
            log::add('veolia_eau', 'debug', 'Date: '.$data['date'].' / Index: '.$data['index'].' / Conso: '.$data['conso'].' / Type de relevé: '.$data['typeReleve']);
            if ($data['index'] > 0) {
                foreach (array('index','conso','typeReleve') as $key) {
                    $cmd = $this->getCmd(null, $key);
                    if (is_object($cmd)) $cmd->event($data[$key], $data['date']);
                }
                $cmd = $this->getCmd(null, 'dateReleve');
                if (is_object($cmd)) $cmd->event($data['date'], $data['date']);
            }
        }

        // 7. Alertes maxday / maxmonth
        $alert = str_replace('#', '', $this->getConfiguration('alert'));
        $maxday   = $this->getConfiguration('maxday');
        $maxmonth = $this->getConfiguration('maxmonth');
        $lastConso = end($datasFetched)['conso'];
        $consomonthSum = array_sum(array_slice($consomonth, -30));

        if (!empty($maxday) && $lastConso >= $maxday && $alert != '') {
            $cmdalerte = cmd::byId($alert);
            if (is_object($cmdalerte)) {
                $cmdalerte->execCmd(array(
                    'title'   => 'Alerte Conso Eau',
                    'message' => 'Conso journalière du '.$lastDate.': '.$lastConso.' litres',
                ));
            }
        }
        if (!empty($maxmonth) && $consomonthSum >= $maxmonth && $alert != '') {
            $cmdalerte = cmd::byId($alert);
            if (is_object($cmdalerte)) {
                $cmdalerte->execCmd(array(
                    'title'   => 'Alerte Conso Eau',
                    'message' => 'Conso mensuelle: '.$consomonthSum.' litres',
                ));
            }
        }

        // 8. Persiste compteur + last (config eqLogic)
        if ($lastIndex > 0) {
            log::add('veolia_eau', 'debug', 'TR: save compteur='.$lastIndex);
            $this->setConfiguration('compteur', $lastIndex);
        }
        if ($lastDate && $lastDate >= $this->getConfiguration('last')) {
            log::add('veolia_eau', 'debug', 'TR: save last='.$lastDate);
            $this->setConfiguration('last', $lastDate);
        }
        $this->save(true);

        return 0;
    }
