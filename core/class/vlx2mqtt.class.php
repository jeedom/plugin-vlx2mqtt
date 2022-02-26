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
require_once __DIR__  . '/../../../../core/php/core.inc.php';

class vlx2mqtt extends eqLogic {

  /*     * ************************* Attributs ****************************** */

  public static $_encryptConfigKey = array('klf_password');

  /*     * *********************** Methodes statiques *************************** */

  public static function handleMqttMessage($_message) {
    $_message = $_message['vlx2mqtt'];
    if (isset($_message['status'])) {
      if ($_message['status'] != config::byKey('status', __CLASS__, '')) {
        log::add(__CLASS__, 'debug', __('Mise à jour du statut de la liaison MQTT', __FILE__) . ' : ' . $_message['status']);
        config::save('status', $_message['status'], __CLASS__);
        if ($_message['status'] == 'CONNECTED') {
          self::registerTopics();
        }
      }
    } else {
      $currentVlx = array_key_first($_message);
      if (is_object($velux = self::byLogicalId('vlx2mqtt::' . $currentVlx, __CLASS__))) {
        log::add(__CLASS__, 'debug', $velux->getHumanName() . ' ' . __('Position du velux', __FILE__) . ' : ' . $_message[$currentVlx]['position']);
        $velux->checkAndUpdateCmd('state', $_message[$currentVlx]['position']);
      } else {
        self::registerTopics($currentVlx);
        log::add(__CLASS__, 'debug', $currentVlx . ' - ' . __('position reçue', __FILE__) . ' : ' . $_message[$currentVlx]['position']);
      }
    }
  }

  public static function installVlx2mqtt() {
    log::add(__CLASS__, 'debug', __('Installation du conteneur docker Velux MQTT...', __FILE__));
    $depPlugins = array(
      'mqtt2' => 'MQTT Manager',
      'docker2' => 'Docker Management'
    );
    foreach ($depPlugins as $pluginId => $pluginName) {
      try {
        $plugin = plugin::byId($pluginId);
      } catch (Exception $e) {
        $errorMessage = __('Le plugin', __FILE__) . ' ' . $pluginName . ' ' . __('n\'est pas installé', __FILE__);
        log::add(__CLASS__, 'debug', __('Installation abandonnée', __FILE__) . ' : ' . $errorMessage);
        throw new Exception($errorMessage);
      }
      if (!$plugin->isActive()) {
        $errorMessage = __('Le plugin', __FILE__) . ' ' . $pluginName . ' ' . __('n\'est pas activé', __FILE__);
        log::add(__CLASS__, 'debug', __('Installation abandonnée', __FILE__) . ' : ' . $errorMessage);
        throw new Exception($errorMessage);
      }
      if ($pluginId == 'mqtt2') {
        if ($plugin->deamon_info()['state'] != 'ok') {
          $errorMessage = __('Le démon du plugin', __FILE__) . ' ' . $pluginName . ' ' . __('n\'est pas démarré', __FILE__);
          log::add(__CLASS__, 'debug', __('Installation abandonnée', __FILE__) . ' : ' . $errorMessage);
          throw new Exception($errorMessage);
        }
      }
    }
    if (config::byKey('klf_ip', __CLASS__, '') == '' || config::byKey('klf_password', __CLASS__, '') == '') {
      $errorMessage = __('Les informations de connexion au KLF 200 ne sont pas renseignées', __FILE__);
      log::add(__CLASS__, 'debug', __('Installation abandonnée', __FILE__) . ' : ' . $errorMessage);
      throw new Exception($errorMessage);
    }

    if (!class_exists('mqtt2')) {
      include_file('core', 'mqtt2', 'class', 'mqtt2');
    }
    if (!isset(mqtt2::getSubscribed()['vlx2mqtt'])) {
      log::add(__CLASS__, 'debug', __('Souscription au topic vlx2mqtt', __FILE__));
      mqtt2::addPluginTopic(__CLASS__, 'vlx2mqtt');
    }

    log::add(__CLASS__, 'debug', __('Collecte des informations de connexion au broker MQTT', __FILE__));
    $mqttConf = mqtt2::getFormatedInfos();
    if ($mqttConf['ip'] == '127.0.0.1') {
      $mqttConf['ip'] = network::getNetworkAccess('internal', 'ip', '', false);
    }

    log::add(__CLASS__, 'debug', __('Ecriture du fichier de configuration docker', __FILE__));
    $configFile = file_get_contents(__DIR__ . '/../../resources/vlx2mqtt.template.cfg');
    $configFile = str_replace(
      ['#mqtt_ip#', '#mqtt_port#', '#mqtt_user#', '#mqtt_password#', '#klf_ip#', '#klf_password#'],
      [$mqttConf['ip'], $mqttConf['port'], $mqttConf['user'], $mqttConf['password'], config::byKey('klf_ip', __CLASS__), config::byKey('klf_password', __CLASS__)],
      $configFile
    );
    file_put_contents(__DIR__ . '/../../data/vlx2mqtt.cfg', $configFile);

    log::add(__CLASS__, 'debug', __('Création de l\'équipement Docker Management Velux MQTT', __FILE__));
    event::add('jeedom::alert', array(
      'level' => 'warning',
      'page' => 'plugin',
      'ttl' => 20000,
      'message' => __('Création de l\'équipement Docker Management Velux MQTT', __FILE__),
    ));

    $compose = str_replace('#jeedom_path#', realpath(__DIR__ . '/../../../../'), file_get_contents(__DIR__ . '/../../resources/docker_compose.yaml'));
    if (!$docker = self::getContainer()) {
      $docker = new docker2();
    } else {
      $docker->stopDocker();
    }
    $docker->setLogicalId('1::vlx2mqtt');
    $docker->setName('Velux MQTT');
    $docker->setIsEnable(1);
    $docker->setEqType_name('docker2');
    $docker->setConfiguration('name', 'vlx2mqtt');
    $docker->setConfiguration('docker_number', 1);
    $docker->setConfiguration('create::mode', 'jeedom_compose');
    $docker->setConfiguration('create::compose', $compose);
    $docker->save();
    try {
      $docker->rm();
      sleep(5);
    } catch (\Throwable $th) {
    }
    $docker->create();
  }

  public static function getContainer() {
    if (!class_exists('docker2')) {
      include_file('core', 'docker2', 'class', 'docker2');
    }
    if (is_object($docker = eqLogic::byLogicalId('1::vlx2mqtt', 'docker2'))) {
      return $docker;
    }
    return false;
  }

  public static function registerTopics($_velux = false) {
    $registeredVlxs = self::getRegisteredVeluxs();
    $subscribeds = ($_velux) ? array('vlx2mqtt/' . $_velux) : self::searchDockerLogs('Subscribing to');
    foreach ($subscribeds as $subscribed) {
      $velux = explode('/', $subscribed)[1];
      if (!is_object(self::byLogicalId('vlx2mqtt::' . $velux, __CLASS__)) && !in_array($velux, $registeredVlxs)) {
        log::add(__CLASS__, 'debug', __('Détection d\'un nouveau velux', __FILE__) . ' : ' . $velux);
        event::add('jeedom::alert', array(
          'level' => 'success',
          'ttl' => 5000,
          'message' => __('Velux MQTT - Nouveau velux détecté', __FILE__) . ' : ' . $velux,
        ));
        array_push($registeredVlxs, $velux);
      }
    }
    if (!empty($registeredVlxs)) {
      cache::set('vlx2mqtt::veluxs', json_encode($registeredVlxs));
    }
  }

  public static function getRegisteredVeluxs() {
    if (cache::exist('vlx2mqtt::veluxs')) {
      return json_decode(cache::byKey('vlx2mqtt::veluxs')->getValue(), true);
    }
    return array();
  }

  public static function createEqlogics() {
    foreach (self::getRegisteredVeluxs() as $velux) {
      if (!is_object($eqLogic = self::byLogicalId('vlx2mqtt::' . $velux, __CLASS__))) {
        log::add(__CLASS__, 'debug', __('Création de l\'équipement velux', __FILE__) . ' : ' . $velux);
        $eqLogic = new self();
        $eqLogic->setEqType_name(__CLASS__);
        $eqLogic->setLogicalId('vlx2mqtt::' . $velux);
        $eqLogic->setName($velux);
        $eqLogic->setIsEnable(1);
        $eqLogic->setIsVisible(1);
        $eqLogic->setCategory('opening', 1);
        $eqLogic->setDisplay('width', '150px');
        $eqLogic->setDisplay('height', '200px');
        $eqLogic->save();
      }
    }
    cache::byKey('vlx2mqtt::veluxs')->remove();
  }

  public static function searchDockerLogs($_search) {
    if ($docker = self::getContainer()) {
      $result = docker2::execCmd(system::getCmdSudo() . ' docker logs -n 100 ' . $docker->getConfiguration('id') . ' 2>&1 | grep "' . $_search . '"', $docker->getConfiguration('docker_number'), null);
      if ($result != '') {
        return preg_split('/\\r\\n|\\r|\\n/', $result);
      }
    }
    return array();
  }

  /*     * *********************** Méthodes d'instance *************************** */

  public function postSave() {
    $state = $this->getCmd('info', 'state');
    if (!is_object($state)) {
      $state = new vlx2mqttCmd();
      $state->setEqLogic_id($this->getId());
      $state->setLogicalId('state');
      $state->setName(__('Etat', __FILE__));
      $state->setOrder(0);
      $state->setIsVisible(0);
      $state->setDisplay('invertBinary', 1);
      $state->setConfiguration('minValue', 0);
      $state->setConfiguration('maxValue', 100);
    }
    $state->setType('info');
    $state->setSubType('numeric');
    $state->setUnite('%');
    $state->save();

    $position = $this->getCmd('action', 'position');
    if (!is_object($position)) {
      $position = new vlx2mqttCmd();
      $position->setEqLogic_id($this->getId());
      $position->setLogicalId('position');
      $position->setName(__('Position', __FILE__));
      $position->setOrder(1);
      $position->setConfiguration('minValue', 0);
      $position->setConfiguration('maxValue', 100);
      $position->setTemplate('dashboard', 'core::timeShutter');
      $position->setTemplate('mobile', 'core::timeShutter');
      $position->setDisplay('showNameOndashboard', 0);
      $position->setDisplay('showNameOnmobile', 0);
    }
    $position->setType('action');
    $position->setSubType('slider');
    $position->setValue($state->getId());
    $position->save();

    $up = $this->getCmd('action', 'up');
    if (!is_object($up)) {
      $up = new vlx2mqttCmd();
      $up->setEqLogic_id($this->getId());
      $up->setLogicalId('up');
      $up->setName(__('Ouvrir', __FILE__));
      $up->setDisplay('icon', '<i class="fas fa-chevron-up"></i>');
      $up->setOrder(2);
    }
    $up->setType('action');
    $up->setSubType('other');
    $up->setValue($state->getId());
    $up->save();

    $stop = $this->getCmd('action', 'stop');
    if (!is_object($stop)) {
      $stop = new vlx2mqttCmd();
      $stop->setEqLogic_id($this->getId());
      $stop->setLogicalId('stop');
      $stop->setName(__('Stop', __FILE__));
      $stop->setDisplay('icon', '<i class="fas fa-stop"></i>');
      $stop->setOrder(3);
    }
    $stop->setType('action');
    $stop->setSubType('other');
    $stop->setValue($state->getId());
    $stop->save();

    $down = $this->getCmd('action', 'down');
    if (!is_object($down)) {
      $down = new vlx2mqttCmd();
      $down->setEqLogic_id($this->getId());
      $down->setLogicalId('down');
      $down->setName(__('Fermer', __FILE__));
      $down->setDisplay('icon', '<i class="fas fa-chevron-down"></i>');
      $down->setOrder(4);
    }
    $down->setType('action');
    $down->setSubType('other');
    $down->setValue($state->getId());
    $down->save();

    if ($state->execCmd() == '') {
      log::add(__CLASS__, 'debug', $this->getHumanName() . ' ' . __('Exécution de la commande STOP pour rafraichir la position du velux', __FILE__));
      $stop->execute();
    }
  }
}

class vlx2mqttCmd extends cmd {

  public function formatValue($_value, $_quote = false) {
    if ($this->getLogicalId() != 'state') {
      return $_value;
    }
    if ($this->getDisplay('invertBinary') == 1) {
      $_value = (100 - $_value);
    }
    return $_value;
  }

  public function execute($_options = array()) {
    if ($this->getType() == 'action') {
      if ($this->getLogicalId() == 'position') {
        if ($this->getCmdValue()->getDisplay('invertBinary') == 1) {
          $value = (string)(100 - $_options['slider']);
        } else {
          $value = $_options['slider'];
        }
      } else {
        $value = strtoupper($this->getLogicalId());
      }
      log::add('vlx2mqtt', 'debug', $this->getHumanName() . ' ' . __('Exécution de la commande', __FILE__) . ' : ' . $value);
      try {
        if (!class_exists('mqtt2')) {
          include_file('core', 'mqtt2', 'class', 'mqtt2');
        }
        mqtt2::publish(str_replace('::', '/', $this->getEqLogic()->getLogicalId()) . '/set', $value);
      } catch (\Throwable $th) {
        log::add('vlx2mqtt', 'error', $this->getHumanName() . ' ' . __('Erreur lors de l\'éxécution de la commande', __FILE__) . ' : ' . $th);
      }
    }
  }
}
