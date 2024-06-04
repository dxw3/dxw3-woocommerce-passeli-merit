<?php
$api_id = ''; $api_key = '';
if( ! empty( get_option( 'dxw3_api_id' ) ) ) $api_id = sanitize_text_field( get_option( 'dxw3_api_id' ) );
if( ! empty( get_option( 'dxw3_api_key' ) ) ) $api_key = sanitize_text_field( get_option( 'dxw3_api_key' ) );

?>
<div id="dxw3-pm-wrapper">
    <div class="dxw3-pm-img">
        <img src="<?php echo esc_url( plugins_url( 'dxw3-passeli-merit' ) ); ?>/images/dxw3_logo_sqr_sm.png">
        <img class='pm' src="<?php echo esc_url( plugins_url( 'dxw3-passeli-merit' ) ); ?>/images/passeli-merit-logo.svg">
    </div>
    <h3>API ID and Key for the Passeli Merit System</h3>
    <form method="post">
        New ID:&nbsp;&nbsp;&nbsp;<input style="width: 360px" type="text" name="id">
        <br>
        New key:&nbsp;<input style="width: 360px" type="text" name="key">
        <br><br>
        <button>Save new ID and key</button>
    </form>
    <?php
        $new_id = ! empty( $_POST[ 'id' ] ) ? sanitize_text_field( $_POST[ 'id' ] ) : '';
        $new_key = ! empty( $_POST[ 'key' ] ) ? sanitize_text_field( $_POST[ 'key' ] ) : '';
        if( ! empty( $new_id ) && ! empty( $new_key ) ) {
            $api_id = $new_id;
            $api_key = $new_key;
            unset( $_POST[ 'id' ] );
            update_option( 'dxw3_api_id', $api_id );
            update_option( 'dxw3_api_key', $api_key );
            echo wp_kses( '<br>API ID has been set as:&nbsp;&nbsp; ' . $api_id, [ 'br' => array() ] );
            echo wp_kses( '<br>API key has been set as: ' . $api_key, [ 'br' => array() ] );
        } else {
            if( ! empty( $api_id ) && ! empty( $api_key ) ) {
                echo wp_kses( '<br><b>Current API ID:&nbsp;&nbsp;</b> ' . $api_id, [ 'br' => array(), 'b' => array() ] );
                echo wp_kses( '<br><b>Current API key:</b> ' . $api_key, [ 'br' => array(), 'b' => array() ] );
            } else {
                echo '<br><b>Insert valid API credentials.';
            }
        }
    ?>
</div>
<?php

