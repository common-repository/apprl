<h2><?php echo __('APPRL settings', 'apprl'); ?></h2>

<div class="apprl-settings-container">
	<?php if(self::$api->connected): ?>
		<div class="apprl-intro"><?php echo __('Disconnect from APPRL', 'apprl'); ?></div>
		<button name="apprl_connect" id="apprl_connect" class="button button-secondary" onclick="window.location.href='<?php echo APPRL__SETTINGS_PAGE; ?>&do=disconnect'"><?php echo __('Disconnect', 'apprl'); ?></button>
	<?php else: ?>
		<div class="apprl-intro"><?php echo __('Connect to your APPRL-account to finish installation', 'apprl'); ?></div>
		<button name="apprl_connect" id="apprl_connect" class="button button-primary" onclick="window.location.href='<?php echo APPRL__SETTINGS_PAGE; ?>&do=connect'"><?php echo __('Connect', 'apprl'); ?></button>
	<?php endif; ?>
</div>

<br />

<?php if(self::$api->connected): ?>
<h2><?php echo __('Options', 'apprl'); ?></h2>

<div class="apprl-settings-container">
	<form method="POST" action="options.php">
	<?php 
		settings_fields( 'apprl_settings' );	//pass slug name of page, also referred to in Settings API as option group name
		do_settings_sections( 'apprl_settings' ); 	//pass slug name of page
		submit_button();
	?>
	</form>
</div>
<?php endif; ?>