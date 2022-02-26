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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
	include_file('desktop', '404', 'php');
	die();
}
?>

<form class="form-horizontal">
	<fieldset>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Adresse IP du KLF 200}}
				<sup><i class="fas fa-question-circle tooltips" title="{{Renseignez l'adresse IP du KLF 200 sur le réseau local}}"></i></sup>
			</label>
			<div class="col-md-4">
				<input class="configKey form-control" data-l1key="klf_ip">
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Mot de passe WiFi du KLF 200}}
				<sup><i class="fas fa-question-circle tooltips" title="{{Renseignez le mot de passe WiFi du KLF 200}}"></i></sup>
			</label>
			<div class="col-md-4">
				<input type="text" class="configKey form-control inputPassword" data-l1key="klf_password">
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Etat du docker}}
				<sup><i class="fas fa-question-circle tooltips" title="{{Statut de l'installation docker Velux MQTT}}"></i></sup>
			</label>
			<div class="col-md-4">
				<?php if (config::byKey('klf_ip', 'vlx2mqtt', '') == '' || config::byKey('klf_password', 'vlx2mqtt', '') == '') {
					echo '<span class="label label-warning">{{Informations de connexion au KLF 200 manquantes}}</span>';
				} else if ($docker = vlx2mqtt::getContainer()) {
					$dockerState = $docker->getCmd('info', 'state')->execCmd();
					$mqttState = config::byKey('status', 'vlx2mqtt', 'DISCONNECTED');
					echo '<span class="label label-' . (($dockerState == 'running') ? 'success' : 'danger') . '">' . $dockerState . '</span> ';
					echo '<span class="label label-' . (($mqttState == 'CONNECTED') ? 'success' : 'danger') . '">' . $mqttState . '</span> ';
					echo '<a class="btn btn-warning" id="bt_installVlx2mqtt">{{Réinstaller Velux MQTT}}</a>';
				} else {
					echo '<a class="btn btn-warning" id="bt_installVlx2mqtt">{{Installer Velux MQTT}}</a>';
				}
				?>
			</div>
		</div>
	</fieldset>
</form>
</div>

<script>
	function vlx2mqtt_postSaveConfiguration() {
		window.location.reload()
	}

	$('#bt_installVlx2mqtt').on('click', function() {
		$.hideAlert()
		$.ajax({
			type: "POST",
			url: "plugins/vlx2mqtt/core/ajax/vlx2mqtt.ajax.php",
			data: {
				action: 'installVlx2mqtt'
			},
			dataType: 'json',
			error: function(request, status, error) {
				handleAjaxError(request, status, error);
			},
			success: function(data) {
				console.log(data)
				if (data.state == 'error') {
					$.fn.showAlert({
						message: data.result,
						level: 'danger'
					})
				} else {
					setTimeout(function() {
						window.location.reload()
					}, 10000)
				}
			}
		})
	})
</script>
