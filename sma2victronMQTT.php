#!/usr/bin/php
<?php
//
// This PHP scrips allows you to retrieve the current power and energy data of a SMA Tripower X inverter
// and provides it to your Victron GX device via MQTT. This will show the SMA Tripower X as inverter in
// the Victron environment. That allows the GX device to calculate the current load of your household
// by calculating GRID-POWER + (PV-POWER - BATTERY-POWER)
// e.g. GRID-POWER=-2kW (feed-in) | PV-POWER=10kW (generating) | BATTERY=5kW (charging)
// => CONSUMPTION-HOSEHOLD = -2kW + (10kW - 5kW) = 3kW
//
// In order to use this script you need to
//
//      - have a linux server with PHP, where this script can run on (e.g. es Raspberry Pi)
//      - install Mosquitto-PHP (see https://github.com/mgdm/Mosquitto-PHP)
//          On a vanilla RPI with RaspbianOS the following commands should do the needed Mosquitto-PHP installations:
//              sudo -s
//              apt -y install php php-dev libmosquitto-dev mosquitto mosquitto-dev mosquitto-clients git php-curl
//              cd /tmp
//              git clone https://github.com/mgdm/Mosquitto-PHP
//              cd Mosquitto-PHP
//              phpize
//              ./configure --with-mosquitto=/usr/lib/arm-linux-gnueabihf/libmosquitto.so
//              make
//              make install
//              echo 'extension=mosquitto.so' > /etc/php/7.4/cli/conf.d/20-mosquitto.ini
//      - activate MQTT in you GX device (Settings->Services->MQTT on LAN (SSL & plaintext)
//      - install the following driver:
//          https://github.com/freakent/dbus-mqtt-devices
//          installation instructions: https://github.com/freakent/dbus-mqtt-devices#Install-and-Setup
//      - configure the settings below in this script
//      - put this script into the folder /usr/local/bin
//      - make the script executable:
//          chmod 755 /usr/local/bin/sma2victronMQTT.php
//      - start the script with
//          /usr/local/bin/sma2victronMQTT.php
//

//
// ### SMA Inverter config ###
//
$ip = '###.###.###.###';            // IP of SMA Tripower X
$user = '########';                 // User to login to SMA Tripower X
$password = '############';         // Corresponding user password to login to the SMA Tripower X

//
// ### Victron GX MQTT config ###
//
$mqtt_server = '###.###.###.###';   // IP or DNS name of your GX device
$mqtt_port = 1883;                  // MQTT port, 1883 by default
$mqtt_tls = false;                  // set to true to use MQTTS (then port should bei 8883)
$mqtt_tls_insecure = true;          // If using TLS with self signed certificates, set this to true
$mqtt_user = '';                    // MQTT User, by default it's empty for Victron GX device
$mqtt_password = '';                // MQTT Password, by default it's empty for Victron GX device
$mqtt_qos = 0;                      // QOS in local networks should be 0 for performance reasons
$mqtt_retain = false;               // it's safe to set retain flag to false

//
// ### Victron PV inverter setting  ###
//
$victron_client_id = 'sma';         // specifies the connection name in the GX console. Define as you want.

//
// ### General config ###
//
$interval = 500;                    // intervall specifies how often this script will read the power
                                    // from the SMA inverter and pushes it to the GX device
$debug = false;                     // set to true if you want to see debug information

//
// ############ no need to make changes below ############
//

set_time_limit(0);

if ($argc > 1)
    $interval = $argv[1];

if ($argc > 2 && $argv[2] == 'debug')
    $debug = true;

$client = new Mosquitto\Client(uniqid('sma2victronMQTT'));
$client->setCredentials($mqtt_user, $mqtt_password);
$client->setTlsInsecure($mqtt_tls_insecure);

$client->onConnect(function () use ($client, &$mid, $victron_client_id, $mqtt_qos) {
    echo "CONNECTED" . PHP_EOL;
    $client->subscribe('device/' . $victron_client_id . '/DBus', $mqtt_qos);
    $mid = $client->publish('device/' . $victron_client_id . '/Status',
        '{"clientId":"' . $victron_client_id . '","connected":1,"version":"1.0","services":{"sma":"pvinverter"}}', $mqtt_qos, true);
});

$client->onMessage(function ($message) use ($client, $victron_client_id, &$portalId, &$deviceId) {
    if ($message->topic == 'device/' . $victron_client_id . '/DBus') {
        $data = json_decode($message->payload, true);
        $portalId = $data['portalId'];
        $deviceId = $data['deviceInstance']['sma'];
        $client->exitLoop();
    }
});

try {
    $client->connect($mqtt_server, $mqtt_port);
    $client->loopForever(1000);
} catch (Exception $exception) {
    echo "Exception: " . $exception->getMessage() . PHP_EOL;
}

$topic_prefix = 'W/' . $portalId . '/pvinverter/' . $deviceId . '/';

$token = login($ip, $user, $password);
if ($token !== false) {
    if ($debug) echo "ACCESS-TOKEN: $token" . PHP_EOL;
} else
    die("Failed to retrieve ACCESS-TOKEN." . PHP_EOL);

$device_data = request_device_data($ip, $token);
if ($device_data !== false) {
    if ($debug)
        echo "DEVICE-DATA: " . json_encode($device_data) . PHP_EOL;
} else
    echo "Failed to retrieve DEVICE-DATA." . PHP_EOL;

$t1 = 0;
while (true) {
    try {
        $live_data = request_live_data($ip, $token);
        if ($live_data !== false) {
            if ($debug) echo json_encode($live_data) . PHP_EOL . PHP_EOL;
            mqtt_send_data($live_data);
        } else
            $token = login($ip, $user, $password);
        $t2 = microtime(true);
        if ($t2 - $t1 >= 180)
        {
            $t1 = $t2;
            $client->publish('device/' . $victron_client_id . '/Status',
                '{"clientId":"' . $victron_client_id . '","connected":1,"version":"1.0","services":{"sma":"pvinverter"}}', $mqtt_qos);
            $client->loop(10);
        }
        usleep($interval * 1000);
    } catch (Exception $exception) {
        $code = $exception->getCode();
        echo $exception->getMessage() . "($code)" . PHP_EOL;
        switch ($code) {
            case 0:
                $client->connect($mqtt_server, $mqtt_port);
                break;
            default:
                echo "Exception: " . $exception->getMessage() . PHP_EOL;
        }
    }
}

$client->disconnect();

function mqtt_send_data($data)
{
    global $client, $topic_prefix, $mqtt_qos, $mqtt_retain, $victron_client_id;
    foreach ($data as $item) {
        switch ($item['channelId']) {
            case 'Measurement.Operation.HealthStt.Ok':
                if (array_key_exists('value', $item['values'][0])) {
                    $client->publish($topic_prefix . 'Ac/MaxPower', '{"value":' . $item['values'][0]['value'] . '}', $mqtt_qos, $mqtt_retain);
                    $client->loop(10);
                }
                break;
            case 'Measurement.GridMs.TotW.Pv':
            //case 'Measurement.PvGen.PvW':
                if (array_key_exists('value', $item['values'][0])) {
                    $client->publish($topic_prefix . 'Ac/Power', '{"value":' . round($item['values'][0]['value']) . '}', $mqtt_qos, $mqtt_retain);
                    $client->loop(10);
                    echo round($item['values'][0]['value'])." W".PHP_EOL;
                }
                break;
            case 'Measurement.Metering.TotWhOut.Pv':
                if (array_key_exists('value', $item['values'][0])) {
                    $client->publish($topic_prefix . 'Ac/Energy/Forward', '{"value":' . round($item['values'][0]['value'] / 1000, 1) . '}', $mqtt_qos, $mqtt_retain);
                    $client->publish($topic_prefix . 'Ac/L1/Energy/Forward', '{"value":' . round($item['values'][0]['value'] / 3000, 1) . '}', $mqtt_qos, $mqtt_retain);
                    $client->publish($topic_prefix . 'Ac/L2/Energy/Forward', '{"value":' . round($item['values'][0]['value'] / 3000, 1) . '}', $mqtt_qos, $mqtt_retain);
                    $client->publish($topic_prefix . 'Ac/L3/Energy/Forward', '{"value":' . round($item['values'][0]['value'] / 3000, 1) . '}', $mqtt_qos, $mqtt_retain);
                    $client->loop(40);
                }
                break;
            case 'Measurement.GridMs.TotA':
                if (array_key_exists('value', $item['values'][0])) {
                    $client->publish($topic_prefix . 'Ac/Current', '{"value":' . round($item['values'][0]['value'], 1) . '}', $mqtt_qos, $mqtt_retain);
                    $client->loop(10);
                }
                break;
            case 'Measurement.GridMs.PhV.phsA':
                if (array_key_exists('value', $item['values'][0])) {
                    $client->publish($topic_prefix . 'Ac/L1/Voltage', '{"value":' . round($item['values'][0]['value']) . '}', $mqtt_qos, $mqtt_retain);
                    $client->loop(10);
                }
                break;
            case 'Measurement.GridMs.PhV.phsB':
                if (array_key_exists('value', $item['values'][0])) {
                    $client->publish($topic_prefix . 'Ac/L2/Voltage', '{"value":' . round($item['values'][0]['value']) . '}', $mqtt_qos, $mqtt_retain);
                    $client->loop(10);
                }
                break;
            case 'Measurement.GridMs.PhV.phsC':
                if (array_key_exists('value', $item['values'][0])) {
                    $client->publish($topic_prefix . 'Ac/L3/Voltage', '{"value":' . round($item['values'][0]['value']) . '}', $mqtt_qos, $mqtt_retain);
                    $client->loop(10);
                }
                break;
            case 'Measurement.GridMs.A.phsA':
                if (array_key_exists('value', $item['values'][0])) {
                    $client->publish($topic_prefix . 'Ac/L1/Current', '{"value":' . round($item['values'][0]['value'], 1) . '}', $mqtt_qos, $mqtt_retain);
                    $client->loop(10);
                }
                break;
            case 'Measurement.GridMs.A.phsB':
                if (array_key_exists('value', $item['values'][0])) {
                    $client->publish($topic_prefix . 'Ac/L2/Current', '{"value":' . round($item['values'][0]['value'], 1) . '}', $mqtt_qos, $mqtt_retain);
                    $client->loop(10);
                }
                break;
            case 'Measurement.GridMs.A.phsC':
                if (array_key_exists('value', $item['values'][0])) {
                    $client->publish($topic_prefix . 'Ac/L3/Current', '{"value":' . round($item['values'][0]['value'], 1) . '}', $mqtt_qos, $mqtt_retain);
                    $client->loop(10);
                }
                break;
            case 'Measurement.GridMs.W.phsA':
                if (array_key_exists('value', $item['values'][0])) {
                    $client->publish($topic_prefix . 'Ac/L1/Power', '{"value":' . round($item['values'][0]['value']) . '}', $mqtt_qos, $mqtt_retain);
                    $client->loop(10);
                }
                break;
            case 'Measurement.GridMs.W.phsB':
                if (array_key_exists('value', $item['values'][0])) {
                    $client->publish($topic_prefix . 'Ac/L2/Power', '{"value":' . round($item['values'][0]['value']) . '}', $mqtt_qos, $mqtt_retain);
                    $client->loop(10);
                }
                break;
            case 'Measurement.GridMs.W.phsC':
                if (array_key_exists('value', $item['values'][0])) {
                    $client->publish($topic_prefix . 'Ac/L3/Power', '{"value":' . round($item['values'][0]['value']) . '}', $mqtt_qos, $mqtt_retain);
                    $client->loop(10);
                }
                break;
            default:
                break;
        }
    }
}

function login($ip, $user, $password)
{
    $url = 'https://' . $ip . '/api/v1/token';

    $post_data = 'grant_type=password&username=' . $user . '&password=' . $password;

    $headers = array(
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json, text/plain, */*'
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $result = ($info['http_code'] == 200) ? true : false;
    if ($result) {
        $body = substr($response, $header_size);
        $auth = json_decode($body, 1);
        if (array_key_exists('access_token', $auth))
            $access_token = $auth['access_token'];
        return $access_token;
    }
    return false;
}

function request_device_data($ip, $token)
{
    $headers = ["Authorization: Bearer " . $token];
    $url = "https://$ip/api/v1/plants/Plant:1/devices/IGULD:SELF";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $result = ($info['http_code'] == 200) ? true : false;
    if ($result) {
        $body = substr($response, $header_size);
        $device_data = json_decode($body, 1);
        if (json_last_error() == JSON_ERROR_NONE)
            return $device_data;
    }
    return false;
}

function request_live_data($ip, $token)
{
    $headers = ["Authorization: Bearer " . $token];
    $post_data = '[{"componentId":"IGULD:SELF"}]';
    $url = "https://$ip/api/v1/measurements/live";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    $response = curl_exec($ch);
    //print_r($response);
    $info = curl_getinfo($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $result = ($info['http_code'] == 200) ? true : false;
    if ($result) {
        $body = substr($response, $header_size);
        $live_data = json_decode($body, 1);
        if (json_last_error() == JSON_ERROR_NONE)
            return $live_data;
    }
    return false;
}

?>
