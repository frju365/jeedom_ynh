<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../core/php/core.inc.php';

class history {
    /*     * *************************Attributs****************************** */

    private $cmd_id;
    private $value;
    private $datetime;
    private $_tableName = 'history';

    /*     * ***********************Methode static*************************** */

    public static function byCmdIdDatetime($_cmd_id, $_datetime) {
        $values = array(
            'cmd_id' => $_cmd_id,
            'datetime' => $_datetime,
        );
        $sql = 'SELECT ' . DB::buildField(__CLASS__) . '
                FROM history
                WHERE cmd_id=:cmd_id
                    AND `datetime`=:datetime';
        $result = DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW, PDO::FETCH_CLASS, __CLASS__);
        if (!is_object($result)) {
            $sql = 'SELECT ' . DB::buildField(__CLASS__) . '
                    FROM historyArch
                    WHERE cmd_id=:cmd_id
                        AND `datetime`=:datetime';
            $result = DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW, PDO::FETCH_CLASS, __CLASS__);
            if (is_object($result)) {
                $result->setTableName('historyArch');
            }
        } else {
            $result->setTableName('history');
        }
        return $result;
    }

    /**
     * Archive les données de history dans historyArch 
     */
    public static function archive() {
        if (config::byKey('historyArchivePackage') >= config::byKey('historyArchiveTime')) {
            config::save('historyArchivePackage', config::byKey('historyArchiveTime') - 1);
        }

        $archiveTime = config::byKey('historyArchiveTime') . ':00:00';
        $archivePackage = config::byKey('historyArchivePackage') . ':00:00';
        if (strlen($archiveTime) < 8) {
            $archiveTime = '0' . $archiveTime;
        }
        if (strlen($archivePackage) < 8) {
            $archivePackage = '0' . $archivePackage;
        }
        $values = array(
            'archiveTime' => $archiveTime,
        );
        $sql = 'SELECT DISTINCT(cmd_id) 
                FROM history 
                WHERE TIMEDIFF(NOW(),`datetime`)>:archiveTime';
        $list_sensors = DB::Prepare($sql, $values, DB::FETCH_TYPE_ALL);
        foreach ($list_sensors as $sensors) {
            $cmd = cmd::byId($sensors['cmd_id']);
            if (is_object($cmd) && $cmd->getType() == 'info' && $cmd->getIsHistorized() == 1) {
                if ($cmd->getSubType() == 'binary') {
                    $values = array(
                        'cmd_id' => $cmd->getId(),
                    );
                    $sql = 'SELECT ' . DB::buildField(__CLASS__) . '
                            FROM history
                            WHERE cmd_id=:cmd_id ORDER BY `datetime` ASC';
                    $history = DB::Prepare($sql, $values, DB::FETCH_TYPE_ALL, PDO::FETCH_CLASS, __CLASS__);
                    for ($i = 1; $i < count($history); $i++) {
                        if ($history[$i]->getValue() != $history[$i - 1]->getValue()) {
                            $history[$i]->setTableName('historyArch');
                            $history[$i]->save();
                            $history[$i]->setTableName('history');
                        }
                        $history[$i]->remove();
                    }
                    $history[0]->setTableName('historyArch');
                    $history[0]->save();
                    $history[0]->setTableName('history');
                    $history[0]->remove();
                    $values = array(
                        'cmd_id' => $cmd->getId(),
                    );
                    $sql = 'SELECT ' . DB::buildField(__CLASS__) . '
                            FROM historyArch
                            WHERE cmd_id=:cmd_id ORDER BY datetime ASC';
                    $history = DB::Prepare($sql, $values, DB::FETCH_TYPE_ALL, PDO::FETCH_CLASS, __CLASS__);
                    for ($i = 1; $i < count($history); $i++) {
                        if ($history[$i]->getValue() == $history[$i - 1]->getValue()) {
                            $history[$i]->setTableName('historyArch');
                            $history[$i]->remove();
                        }
                    }
                } else {
                    $values = array(
                        'cmd_id' => $sensors['cmd_id'],
                        'archiveTime' => $archiveTime,
                    );
                    $sql = 'SELECT MIN(`datetime`) as oldest 
                            FROM history 
                            WHERE TIMEDIFF(NOW(),`datetime`)>:archiveTime 
                            AND cmd_id=:cmd_id';
                    $oldest = DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW);

                    while ($oldest['oldest'] != null) {
                        $values = array(
                            'cmd_id' => $sensors['cmd_id'],
                            'oldest' => $oldest['oldest'],
                            'archivePackage' => $archivePackage,
                        );
                        $sql = 'SELECT AVG(value) as value,
                                FROM_UNIXTIME(AVG(UNIX_TIMESTAMP(`datetime`))) as datetime  
                                FROM history 
                                WHERE TIMEDIFF(`datetime`,:oldest)<:archivePackage 
                                AND cmd_id=:cmd_id';
                        $avg = DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW);

                        $history = new self();
                        $history->setCmd_id($sensors['cmd_id']);
                        $history->setValue($avg['value']);
                        $history->setDatetime($avg['datetime']);
                        $history->setTableName('historyArch');
                        $history->save();

                        $values = array(
                            'cmd_id' => $sensors['cmd_id'],
                            'oldest' => $oldest['oldest'],
                            'archivePackage' => $archivePackage,
                        );
                        $sql = 'DELETE FROM history 
                                WHERE TIMEDIFF(`datetime`,:oldest)<:archivePackage 
                                AND cmd_id=:cmd_id';
                        DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW);

                        $values = array(
                            'cmd_id' => $sensors['cmd_id'],
                            'archiveTime' => $archiveTime,
                        );
                        $sql = 'SELECT MIN(`datetime`) as oldest 
                                FROM history 
                                WHERE TIMEDIFF(NOW(),`datetime`)>:archiveTime 
                                    AND cmd_id=:cmd_id';
                        $oldest = DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW);
                    }
                }
            }
        }
        self::fillHole();
    }

    public static function fillHole() {
        $now = strtotime('now');
        $archiveTime = (config::byKey('historyArchiveTime') + 1) * 3600;
        $packetTime = (config::byKey('historyArchivePackage')) * 3600;
        $endTime = date('Y-m-d H:i:s', $now - $archiveTime);
        foreach (cmd::allHistoryCmd() as $cmd) {
            $prevDatetime = null;
            $prevValue = 0;
            foreach ($cmd->getHistory(null, $endTime) as $history) {
                if ($prevDatetime != null) {
                    $datetime = strtotime($history->getDatetime());
                    $prevDatetime = date('Y-m-d H:00:00', strtotime($prevDatetime) + $packetTime);
                    while (($now - strtotime($prevDatetime) > $archiveTime) && strtotime($prevDatetime) < $datetime) {
                        $newHistory = new history();
                        $newHistory->setCmd_id($cmd->getId());
                        $newHistory->setDatetime($prevDatetime);
                        if ($cmd->getConfiguration('historyDefaultValue', null) === '#previsous#') {
                            $newHistory->setValue($prevValue);
                        } else {
                            $newHistory->setValue($cmd->getConfiguration('historyDefaultValue', null));
                        }
                        $newHistory->setTableName('historyArch');
                        $newHistory->save();
                        $prevDatetime = date('Y-m-d H:00:00', strtotime($prevDatetime) + $packetTime);
                    }
                }
                $prevDatetime = $history->getDatetime();
                $prevValue = $history->getValue();
            }
        }
    }

    /**
     *
     * @param int $_equipement_id id de l'équipement dont on veut l'historique des valeurs
     * @return array des valeurs de l'équipement 
     */
    public static function all($_cmd_id, $_startTime = null, $_endTime = null) {
        $values = array(
            'cmd_id' => $_cmd_id,
        );
        if ($_startTime != null) {
            $values['startTime'] = $_startTime;
        }
        if ($_endTime != null) {
            $values['endTime'] = $_endTime;
        }
        $sql = 'SELECT ' . DB::buildField(__CLASS__) . '
                FROM (
                    SELECT ' . DB::buildField(__CLASS__) . '
                    FROM history
                    WHERE cmd_id=:cmd_id ';
        if ($_startTime != null) {
            $sql .= ' AND datetime>=:startTime';
        }
        if ($_endTime != null) {
            $sql .= ' AND datetime<=:endTime';
        }
        $sql .= ' UNION ALL
                    SELECT ' . DB::buildField(__CLASS__) . '
                    FROM historyArch
                    WHERE cmd_id=:cmd_id';
        if ($_startTime != null) {
            $sql .= ' AND `datetime`>=:startTime';
        }
        if ($_endTime != null) {
            $sql .= ' AND `datetime`<=:endTime';
        }
        $sql .=' ) as dt
               ORDER BY `datetime` ASC ';
        return DB::Prepare($sql, $values, DB::FETCH_TYPE_ALL, PDO::FETCH_CLASS, __CLASS__);
    }

    public static function getStatistique($_cmd_id, $_startTime, $_endTime) {
        $values = array(
            'cmd_id' => $_cmd_id,
            'startTime' => $_startTime,
            'endTime' => $_endTime,
        );
        $sql = 'SELECT AVG(value) as avg, MIN(value) as min, MAX(value) as max
                FROM (
                    SELECT *
                    FROM history
                    WHERE cmd_id=:cmd_id 
			AND `datetime`>=:startTime
			AND `datetime`<=:endTime
                    UNION ALL
                    SELECT *
                    FROM historyArch
                    WHERE cmd_id=:cmd_id  
                        AND `datetime`>=:startTime
                        AND `datetime`<=:endTime
                ) as dt';
        return DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW);
    }

    public static function getTendance($_cmd_id, $_startTime, $_endTime) {
        $values = array();
        foreach (self::all($_cmd_id, $_startTime, $_endTime) as $history) {
            $values[] = $history->getValue();
        }
        if (count($values) == 0) {
            $x_mean = 0;
        } else {
            $x_mean = array_sum(array_keys($values)) / count($values);
        }
        if (count($values) == 0) {
            $y_mean = 0;
        } else {
            $y_mean = array_sum($values) / count($values);
        }
        $base = 0.0;
        $divisor = 0.0;
        foreach ($values as $i => $value) {
            $base += ($i - $x_mean) * ($value - $y_mean);
            $divisor += ($i - $x_mean) * ($i - $x_mean);
        }
        if ($divisor == 0) {
            return 0;
        }
        return ($base / $divisor);
    }

    /**
     * Fonction qui recupere les valeurs actuellement des capteurs, 
     * les mets dans la BDD et archive celle-ci
     */
    public static function historize() {
        $listHistorizedCmd = cmd::allHistoryCmd(true);
        foreach ($listHistorizedCmd as $cmd) {
            try {
                if ($cmd->getEqLogic()->getIsEnable() == 1) {
                    $value = $cmd->execCmd(null, 0);
                    if ($value !== false) {
                        $cmd->addHistoryValue($value);
                    }
                }
            } catch (Exception $e) {
                log::add('historized', 'error', 'Erreur sur ' . $cmd->getHumanName() . ' : ' . $e->getMessage(), 'historized::cmd::' . $cmd->getId());
            }
        }
    }

    public static function emptyHistory($_cmd_id) {
        $values = array(
            'cmd_id' => $_cmd_id,
        );
        $sql = 'DELETE FROM history WHERE cmd_id=:cmd_id';
        DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW);
        $sql = 'DELETE FROM historyArch WHERE cmd_id=:cmd_id';
        return DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW);
    }

    public static function getHistoryFromCalcul($_strcalcul, $_dateStart = null, $_dateEnd = null) {
        $now = strtotime('now');
        $archiveTime = (config::byKey('historyArchiveTime') + 1) * 3600;
        $packetTime = (config::byKey('historyArchivePackage')) * 3600;
        $test = new evaluate();
        $value = array();
        $cmd_histories = array();
        preg_match_all("/#([0-9]*)#/", $_strcalcul, $matches);
        if (count($matches[1]) > 0) {
            foreach ($matches[1] as $cmd_id) {
                if (is_numeric($cmd_id)) {
                    $cmd = cmd::byId($cmd_id);
                    if (is_object($cmd) && $cmd->getIsHistorized() == 1) {
                        $prevDatetime = null;
                        $prevValue = 0;
                        $histories_cmd = $cmd->getHistory($_dateStart, $_dateEnd);
                        $histories_cmd_count = count($histories_cmd);
                        for ($i = 0; $i < $histories_cmd_count; $i++) {
                            if (!isset($cmd_histories[$histories_cmd[$i]->getDatetime()])) {
                                $cmd_histories[$histories_cmd[$i]->getDatetime()] = array();
                            }
                            if (!isset($cmd_histories[$histories_cmd[$i]->getDatetime()]['#' . $cmd_id . '#'])) {
                                if ($prevDatetime != null) {
                                    $datetime = strtotime($histories_cmd[$i]->getDatetime());
                                    while (($now - strtotime($prevDatetime) > $archiveTime) && strtotime($prevDatetime) < $datetime) {
                                        $prevDatetime = date('Y-m-d H:00:00', strtotime($prevDatetime) + $packetTime);
                                        $cmd_histories[$prevDatetime]['#' . $cmd_id . '#'] = 0;
                                    }
                                    while (($now - strtotime($prevDatetime)) > 300 && strtotime($prevDatetime) < $datetime) {
                                        $prevDatetime = date('Y-m-d H:i:00', strtotime($prevDatetime) + 300);
                                        $cmd_histories[$prevDatetime]['#' . $cmd_id . '#'] = $prevValue;
                                    }
                                }
                                $cmd_histories[$histories_cmd[$i]->getDatetime()]['#' . $cmd_id . '#'] = $histories_cmd[$i]->getValue();
                            }
                            $prevDatetime = $histories_cmd[$i]->getDatetime();
                            $prevValue = $histories_cmd[$i]->getValue();
                        }
                    }
                }
            }
            foreach ($cmd_histories as $datetime => $cmd_history) {
                $datetime = floatval(strtotime($datetime . " UTC"));
                $calcul = template_replace($cmd_history, $_strcalcul);
                try {
                    $result = floatval($test->Evaluer($calcul));
                    $value[$datetime] = $result;
                } catch (Exception $e) {
                    
                }
            }
        } else {
            $value = $_strcalcul;
        }
        if (is_array($value)) {
            ksort($value);
        }
        return $value;
    }

    /*     * *********************Methode d'instance************************* */

    public function save($_cmd = null) {
        if ($_cmd == null) {
            $cmd = $this->getCmd();
            if ($cmd->getType() != 'info') {
                throw new Exception(__('Impossible d\'historiser cette commande car elle n\'est pas de type info : ', __FILE__) . $cmd->getHumanName());
            }
            if ($cmd->getIsHistorized() != 1) {
                throw new Exception(__('Impossible d\'historiser cette commande car elle n\'est pas marquer comme "à historiser" : ', __FILE__) . $cmd->getHumanName());
            }
        } else {
            $cmd = $_cmd;
        }
        if ($this->getTableName() == 'history' && (!jeedom::isStarted() || !jeedom::isDateOk())) {
            return;
        }
        if ($this->getDatetime() == '') {
            $this->setDatetime(date('Y-m-d H:i:s'));
        }
        if ($cmd->getSubType() != 'binary') {
            if ($this->getTableName() == 'history') {
                $minute = date('i', strtotime($this->getDatetime()));
                if ($minute != 0) {
                    $decimal = floor($minute / 10) * 10;
                    $first = $minute - $decimal;
                    $minute = ($first >= 5) ? $decimal + 5 : $decimal;
                }
                $this->setDatetime(date('Y-m-d H:' . $minute . ':00', strtotime($this->getDatetime())));
                $values = array(
                    'cmd_id' => $this->getCmd_id(),
                    'datetime' => $this->getDatetime(),
                );
                $sql = 'SELECT ' . DB::buildField(__CLASS__) . '
                        FROM history
                        WHERE cmd_id=:cmd_id 
                            AND `datetime`=:datetime';
                $old = DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW, PDO::FETCH_CLASS, __CLASS__);
                if (is_object($old) && $old->getValue() !== '') {
                    if ($this->getValue() === 0) {
                        $values = array(
                            'cmd_id' => $this->getCmd_id(),
                            'datetime' => date('Y-m-d H:i:00', strtotime($this->getDatetime()) + 300),
                            'value' => $this->getValue(),
                        );
                        $sql = 'REPLACE INTO history
                                SET cmd_id=:cmd_id, 
                                    `datetime`=:datetime,
                                    value=:value';
                        DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW);
                        return;
                    }
                    $this->setValue(($old->getValue() + $this->getValue()) / 2);
                }
            }
            if ($this->getTableName() == 'historyArch') {
                $this->setDatetime(date('Y-m-d H:00:00', strtotime($this->getDatetime())));
            }
        } else {
            if ($this->getValue() >= 1) {
                $this->setValue(1);
            } else {
                $this->setValue(0);
            }
        }
        $values = array(
            'cmd_id' => $this->getCmd_id(),
            'datetime' => $this->getDatetime(),
            'value' => $this->getValue(),
        );
        $sql = 'REPLACE INTO ' . $this->getTableName() . '
                SET cmd_id=:cmd_id, 
                    `datetime`=:datetime,
                    value=:value';
        DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW);
    }

    public function remove() {
        DB::remove($this);
    }

    /*     * **********************Getteur Setteur*************************** */

    public function getCmd_id() {
        return $this->cmd_id;
    }

    public function getCmd() {
        return cmd::byId($this->cmd_id);
    }

    public function getValue() {
        return $this->value;
    }

    public function getDatetime() {
        return $this->datetime;
    }

    public function getTableName() {
        return $this->_tableName;
    }

    public function setTableName($_tableName) {
        $this->_tableName = $_tableName;
    }

    public function setCmd_id($cmd_id) {
        $this->cmd_id = $cmd_id;
    }

    public function setValue($value) {
        if (strpos($value, '.') !== false) {
            $this->value = str_replace(',', '', $value);
        } else {
            $this->value = str_replace(',', '.', $value);
        }
    }

    public function setDatetime($datetime) {
        $this->datetime = $datetime;
    }

}

?>
