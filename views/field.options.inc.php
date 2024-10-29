<input name="<?php print $name; ?>" class="apprl-first-radio" type="radio" value="Yes" <?php echo checked( 'Yes', $value, false ); ?> />
<?php echo __( 'Yes', 'apprl' ); ?>

<input name="<?php print $name; ?>" class="apprl-second-radio" type="radio" value="No" <?php echo checked( 'No', $value, false ); ?> />
<?php echo __( 'No', 'apprl' ); ?>