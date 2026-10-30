<?php
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
    include_file('desktop', '404', 'php');
    die();
}
?>
<form class="form-horizontal">
  <fieldset>
    <legend><i class="fas fa-user"></i> {{Compte UrbanHello}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Identifiant (e-mail)}}</label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="username" autocomplete="username"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Mot de passe}}</label>
      <div class="col-md-4">
        <input type="password" class="configKey form-control" data-l1key="password" autocomplete="current-password"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label"></label>
      <div class="col-md-6">
        <a class="btn btn-default" id="bt_remiTestLogin"><i class="fas fa-plug"></i> {{Tester la connexion}}</a>
        <a class="btn btn-success" id="bt_remiSync"><i class="fas fa-sync"></i> {{Synchroniser les REMI}}</a>
        <sup><i class="fas fa-question-circle tooltips" title="{{Sauvegardez la configuration avant de synchroniser}}"></i></sup>
      </div>
    </div>
  </fieldset>
</form>
<script>
  $('#bt_remiTestLogin').on('click', function () {
    $.ajax({
      type: 'POST', url: 'plugins/remi/core/ajax/remi.ajax.php',
      data: {
        action: 'testLogin',
        username: $('.configKey[data-l1key=username]').value(),
        password: $('.configKey[data-l1key=password]').value()
      },
      dataType: 'json',
      success: function (data) {
        if (data.state != 'ok') {
          $.fn.showAlert({message: data.result, level: 'danger'});
          return;
        }
        $.fn.showAlert({message: '{{Connexion réussie}}', level: 'success'});
      }
    });
  });
  $('#bt_remiSync').on('click', function () {
    $.ajax({
      type: 'POST', url: 'plugins/remi/core/ajax/remi.ajax.php',
      data: {action: 'syncRemi'}, dataType: 'json',
      success: function (data) {
        if (data.state != 'ok') {
          $.fn.showAlert({message: data.result, level: 'danger'});
          return;
        }
        $.fn.showAlert({message: '{{Synchronisation terminée}}', level: 'success'});
      }
    });
  });
</script>
