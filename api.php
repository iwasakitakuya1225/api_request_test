<?php

const COOKIE_PATH = 'tmp/cookie';
const TOKEN_PATH = 'tmp/token';

/**
 * 実行メイン
 *
 * @param $argv
 */
function main($argv) {

    if (!isset($argv[1])) {
        echo '第一引数にリクエストパスを入力してください。';
        exit();
    }
    // リクエストパス
    $path = trim($argv[1], " \t\n\r\0\x0B/");
    // リクエストパラメータ
    $api_params_file = isset($argv[2]) ? $argv[2] : 'api_params';

    // cookieファイルがない場合作成する
    if (!file_exists(COOKIE_PATH)) {
        file_put_contents(COOKIE_PATH, '');
    }

    // APIリクエスト開始
    $res = api_request($path, $api_params_file);
    echo $res . "\n";
}

/**
 * APIリクエストを実行する
 *
 * @param string $path
 * @param string $api_params_file
 * @return string
 */
function api_request($path, $api_params_file) {
    // access_tokenとrefresh_tokenを取得する
    if (!file_exists(TOKEN_PATH)) {
        $token = get_access_token_and_refresh_token();
    }else{
        $token = json_decode(file_get_contents(TOKEN_PATH), true);
    }

    // リクエストパラメータを作成する
    $request_params = [
        'access_token' => $token['access_token'],
        'refresh_token' => $token['refresh_token'],
    ];
    $request_params = array_merge($request_params, get_file_data($api_params_file));

    $request_url = get_api_request_url($path);
    echo $request_url . "\n";

    // リクエスト開始
    $res = request(
        $request_url,
        true,
        $request_params
    );

    $res_array = json_decode($res[0], true);
    if (isset($res_array['code']) && $res_array['code'] === '002002') {
        echo 'トークンエラーのため再取得します。';
        file_put_contents('tmp/cookie', '');
        unlink(TOKEN_PATH);
        return api_request($path, $api_params_file);
    }

    file_put_contents(TOKEN_PATH, json_encode(['access_token' => $res_array['access_token'], 'refresh_token' => $res_array['refresh_token']]));

    return $res[0];
}

/**
 * 環境変数を取得する
 *
 * @param string $key
 * @return string
 */
function get_env($key) {
    static $env;
    if (is_null($env)) {
        $env = get_file_data('env');
    }

    return isset($env[$key]) ? $env[$key] : null;
}

/**
 * 指定したファイルデータを連想配列にして返す
 *
 * @param string $file_name
 * @return array
 */
function get_file_data($file_name) {
    $file_data = [];
    $file = fopen($file_name, "r");
    if($file){
        while ($line = fgets($file)) {
            $data = explode('=', $line);
            if (count($data) === 2) {
                $file_data[trim($data[0])] = trim($data[1]);
            }
        }
    }
    fclose($file);
    return $file_data;
}

/**
 * APIリクエスト用のURLを取得する
 *
 * @param string path
 * @return string
 */
function get_api_request_url($path) {
    return get_env('API_SERVER') . $path;
}

/**
 * ログインAPIのURLを取得する
 *
 * @return string
 */
function get_sign_in_url() {
    $path = sprintf('users/sign_in?client_id=%s', get_env('CLIENT_ID'));
    return get_env('BASE_SERVER') . $path;
}

/**
 * access_token取得APIのURLを取得する
 *
 * @return string
 */
function get_api_neauth_path() {
    $path = sprintf('api_neauth');
    return get_api_request_url($path);
}

/**
 * curlでリクエストを実行する
 *
 * @param string $url
 * @param bool $is_post
 * @param array $post_data
 * @return array
 */
function request($url, $is_post = false, $post_data = []) {

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);

    if ($is_post) {
        curl_setopt($ch,CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
    }

    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER, false);  // オレオレ証明書対策
    curl_setopt($ch,CURLOPT_SSL_VERIFYHOST, false);  //
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch,CURLOPT_FOLLOWLOCATION,true);
    curl_setopt($ch,CURLOPT_MAXREDIRS,10);
    curl_setopt($ch,CURLOPT_AUTOREFERER,true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, COOKIE_PATH);
    curl_setopt($ch, CURLOPT_COOKIEFILE, COOKIE_PATH);

    $res = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch); //終了
    return [$res, $info];
}

/**
 * ログイン画面をスクレイピングしてauthenticity_tokenを取得する
 *
 * @return string
 */
function get_authenticity_token() {
    $res = request(get_sign_in_url());
    $dom = new DOMDocument;
    @$dom->loadHTML(mb_convert_encoding($res[0], 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);
    $input_authenticity_token = $xpath->query("//input[@name='authenticity_token']/@value");

    if (!$input_authenticity_token) {
        echo "authenticity_tokenの取得に失敗しました。ログインページのURLもしくはDOM要素が変わっています。";
        echo json_encode($res) . "\n";
        exit();
    }

    return $input_authenticity_token->item(0)->nodeValue;
}

/**
 * ログインを実行しuidとstateを取得する
 *
 * @return array
 */
function get_uid_and_state() {
    // ログインするためのauthenticity_tokenを取得する
    $authenticity_token = get_authenticity_token();

    // ログインしてuidとstateを取得する
    $res = request(
        get_sign_in_url(),
        true,
        [
            'user[login_code]' => get_env('LOGIN_ID'),
            'user[password]' => get_env('LOGIN_PASSWORD'),
            'authenticity_token' => $authenticity_token,
        ]
    );

    $parse_url = parse_url($res[1]['url']);
    parse_str($parse_url['query'], $query);
    if (!isset($query['uid']) || !isset($query['state'])) {
        echo "uidとstateの取得に失敗しました。ログインに失敗しています。\n ";
        echo json_encode($res) . "\n";
        exit();
    }

    return ['uid' => $query['uid'], 'state' => $query['state']];
}

/**
 * APIリクエストを実行しaccess_tokenとrefresh_tokenを取得する
 *
 * @return array
 */
function get_access_token_and_refresh_token() {

    // uidとstateを取得する
    $data = get_uid_and_state();

    $res = request(
        get_api_neauth_path(),
        true,
        [
            'uid' => $data['uid'],
            'state' => $data['state'],
            'client_id' => get_env('CLIENT_ID'),
            'client_secret' => get_env('CLIENT_SECRET'),
        ]
    );

    $result = json_decode($res[0], true);
    if (!isset($result['access_token']) || !isset($result['refresh_token'])) {
        echo "access_tokenとrefresh_tokenの取得に失敗しました。APIリクエストに失敗しています。\n";
        echo json_encode($res) . "\n";
        exit();
    }

    return ['access_token' => $result['access_token'], 'refresh_token' => $result['refresh_token']];
}

main($argv);