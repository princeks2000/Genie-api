<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
class model extends \Envms\FluentPDO\Query
{
  public $stripe_private_key;
  private $host = DBHOST;
  private $dbname = DBNAME;
  private $user = DBUSER;
  private $pass = DBPASS;
  protected $db;
  protected $pdo;
  protected $datetime;
  protected $date;
  protected $time;
  protected $req;
  protected $statuscode;
  protected $data;
  public $queuebookingid;
  function __construct($pdo)
  {
    $this->datetime = date('Y-m-d H:i:s');
    $this->date = date('Y-m-d');
    $this->time = date('H:i:s');
    parent::__construct($pdo);
    $this->pdo = $pdo;
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
      $json_string = file_get_contents("php://input");
      if ($json_string) {
        $this->req = json_decode($json_string, true);
        if (json_last_error() != JSON_ERROR_NONE) {
          http_response_code(500);
          echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
          die;
        }
      }
    }
  }
  public function out($data, $statuscode)
  {
    $this->statuscode = $statuscode;
    $this->data = $data;
    return $this;
  }
  public function getstatus()
  {
    return $this->statuscode;
  }
  public function getdata()
  {
    return $this->data;
  }
  public function __call($method, $params)
  {
    return $this->out(['status' => 'error', 'message' => 'Model not defined'], 501);
  }
  public function render_template($template_name, $data = [])
  {
    $file = __DIR__ . '/../templates/' . $template_name . '.php';
    if (file_exists($file)) {
      extract($data);
      ob_start();
      include $file;
      return ob_get_clean();
    }
    return '';
  }
  public function _email($from, $to, $message, $subject, $cc = [])
  {
    return $this->googlemailer($from, $to, $message, $subject, $cc);
  }
  public function googlemailer($from, $to, $message, $subject = '', $cc = [], $attachment = null)
  {
    $mail = new PHPMailer;
    $mail->SMTPDebug = 0;
    $mail->isSMTP();                                      // Set mailer to use SMTP
    $mail->Host = 'smtp.gmail.com'; // Specify main and backup server
    $mail->Port = 587;
    $mail->SMTPAuth = true;                               // Enable SMTP authentication
    $mail->Username = GMAIL_USERNAME;                   // SMTP username
    $mail->Password = GMAIL_PASSWORD;               // SMTP password
    $mail->SMTPSecure = 'tls';                            // Enable encryption, 'ssl' also accepted
    //Set the SMTP port number - 587 for authenticated TLS
    $mail->setFrom($from, 'Genie');     //Set who the message is to be sent from
    $mail->addReplyTo($from, 'Genie');     //Set who the message is to be sent from
    $mail->addAddress($to, '');  // Add a recipient
    if ($attachment) {
      if (is_array($attachment)) {
        foreach ($attachment as $key => $value) {
          $opts = array('http' => array('header' => 'Cookie: ' . $_SERVER['HTTP_COOKIE'] . "\r\n"));
          $context = stream_context_create($opts);
          session_write_close();
          $stringify = file_get_contents($value['url'], false, $context);
          $mail->addStringAttachment($stringify, $value['name']);
        }
      }
    }
    $mail->WordWrap = 50;                                 // Set word wrap to 50 characters
    $mail->isHTML(true);                                  // Set email format to HTML
    $mail->Subject = $subject;
    $mail->Body = $message;
    $mail->AltBody = '';
    foreach ($cc as $key => $one) {
      $mail->AddCC($one, '');
    }
    $mail->send();
  }
  public function send_grid_smtp($from, $to, $message, $subject = '', $cc = [])
  {
    $mail = new PHPMailer(true);
    try {
      //Server settings
      $mail->SMTPDebug = 0;                     // Enable verbose debug output
      $mail->isSMTP();                                            // Send using SMTP
      $mail->Host = 'smtp.sendgrid.net';                    // Set the SMTP server to send through
      $mail->SMTPAuth = true;                                   // Enable SMTP authentication
      $mail->Username = 'apikey';                     // SMTP username
      $mail->Password = SENDGRID_API_KEY;                               // SMTP password
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;         // Enable TLS encryption; `PHPMailer::ENCRYPTION_SMTPS` encouraged
      $mail->Port = 587;                                    // TCP port to connect to, use 465 for `PHPMailer::ENCRYPTION_SMTPS` above
      //Recipients
      $mail->setFrom($from, '');     //Set who the message is to be sent from
      $mail->addReplyTo($from, '');  //Set an alternative reply-to address
      $mail->addAddress($to, '');
      // Content
      $mail->isHTML(true);                                  // Set email format to HTML
      $mail->Subject = $subject;
      $mail->Body = $message;
      $mail->AltBody = $message;
      $mail->send();
      //echo 'Message has been sent';
    } catch (Exception $e) {
      echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
    }
  }
  public function send_grid($from, $to, $message, $subject = '', $cc = [])
  {
    $url = 'https://api.sendgrid.com/';
    $user = 'bombaybazar';
    $pass = 'market@2015';
    $json_string = array(
      'to' => array($to),
      'category' => 'Repbro'
    );
    //$sendgridemail = array($userdetails['email'],'aarvinms@gmail.com');
    $params = array(
      'api_user' => $user,
      'api_key' => $pass,
      'x-smtpapi' => json_encode($json_string),
      'to' => $to,
      'subject' => $subject,
      'html' => $message,
      'from' => $from,
      'fromname' => $from,
    );
    foreach ($cc as $key => $one) {
      $params['cc[' . $key . ']'] = $one;
    }
    $request = $url . 'api/mail.send.json';
    // Generate curl request
    $session = curl_init($request);
    // Tell curl to use HTTP POST
    curl_setopt($session, CURLOPT_POST, true);
    // Tell curl that this is the body of the POST
    curl_setopt($session, CURLOPT_POSTFIELDS, $params);
    // Tell curl not to return headers, but do return the response
    curl_setopt($session, CURLOPT_HEADER, false);
    // Tell PHP not to use SSLv3 (instead opting for TLS)
    curl_setopt($session, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
    curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
    return $response = curl_exec($session);
    curl_close($session);
    return $response;
  }
  public function send_notification($message, $title, $customer_id = null)
  {
    if ($customer_id) {
      $phone_code = $this->db->from('app_key')->where('id', $customer_id)->fetch('phone_code');
    } else {
      $token = "/topics/all";
    }
    $url = 'https://fcm.googleapis.com/fcm/send';
    $fields = array(
      'to' => $phone_code,
      'notification' => array(
        "body" => $message,
        "title" => $title,
        "sound" => true,
        "alert" => true,
        "click_action" => "FCM_PLUGIN_ACTIVITY",
        "icon" => "icon.png"
      )
    );
    $fields = json_encode($fields);
    $headers = array(
      'Authorization: key=' . "AIzaSyC1A_4JwILVrVbmx3ms7dMd_adYdrc08s4",
      'Content-Type: application/json'
    );
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $result = curl_exec($ch);
    unset($ch);
  }
  public function send_sms($mobile, $message)
  {
    if (is_array($mobile)) {
      foreach ($mobile as $key => $number) {
        $this->text_local('91' . $number, $message);
      }
    } else {
      $numbers = '91' . $mobile;
      $this->text_local($numbers, $message);
    }
  }
  public function text_local($numbers, $message)
  {
    $apiKey = urlencode('/JepfmpznVo-vefC3T0IOqgPYi2uwyNwbr2LU8eG0n');
    $sender = urlencode('AWCSPL');
    $message = rawurlencode($message);
    $data = array('apikey' => $apiKey, 'numbers' => $numbers, 'sender' => $sender, 'message' => $message);
    $ch = curl_init('https://api.textlocal.in/send/');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $values = ['datetime' => date('Y-m-d H:i:s'), 'numbers' => $numbers, 'message' => $message, 'response' => $response];
    $this->db->insertinto('sms_log')->values($values)->execute();
    curl_close($ch);
    return true;
  }
  public function onesignal_notification($message, $title, $big_picture = null, $customer_id = null)
  {
    if ($customer_id) {
      $phone_code = $this->db->from('app_key')->where('id', $customer_id)->fetch('phone_code');
      if ($phone_code) {
        $request = [];
        $request["app_id"] = "52a8588e-1fba-4c1e-a55a-c55944d7569a";
        $request["contents"] = $message;
        $request["headings"] = $title;
        $request["name"] = $title;
        $request["big_picture"] = $big_picture;
        $request["include_player_ids"][] = $phone_code;
        $curl = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_URL => '',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_POSTFIELDS => json_encode($request),
          CURLOPT_HTTPHEADER => array(
            'Authorization: Basic NmQxYjg5OTMtMGY4ZC00NTcxLTliZGYtZDgxOWE0ZWE3MWM4',
            'Content-Type: application/json'
          ),
        ));
        $response = curl_exec($curl);
        $values = [];
        $values['datetime'] = date('Y-m-d H:i:s');
        $values['request'] = json_encode($request);
        $values['response'] = $response;
        $this->insertinto('one_signal_log')->values($values)->execute();
        unset($curl);
      }
    }
  }
  public function getstripeprivatekey()
  {
    // $stripe_key = $this->from('stripe_key')->fetch();
    // if($stripe_key){
    // 	if($stripe_key['live'] == 1){
    // 		return $stripe_key['live_private_key'];
    // 	}
    // 	else{
    // 		return $stripe_key['test_private_key'];
    // 	}
    // }
    // return $this->stripe_private_key;
  }
  public function getstripepublickey()
  {
    // $stripe_key = $this->from('stripe_key')->fetch();
    // if($stripe_key){
    // 	if($stripe_key['live'] == 1){
    // 		return $stripe_key['live_public_key'];
    // 	}
    // 	else{
    // 		return $stripe_key['test_public_key'];
    // 	}
    // }
    // return '';
  }
  public function initStripe()
  {
    // require_once __DIR__ . '/../vendor/stripe/stripe-php/init.php';
    // \Stripe\Stripe::setApiKey($this->getstripeprivatekey());
    // return \Stripe\Stripe::class;
  }
  public function calculateWorkingDays($start_date, $end_date, $include_saturday = false)
  {
    // Calculate working days between start_date and end_date
    // Sunday is always excluded (holiday)
    // Saturday is included only if include_saturday is true

    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end->modify('+1 day'); // Include end date

    $working_days = 0;
    $current = clone $start;

    while ($current < $end) {
      $day_of_week = (int) $current->format('w'); // 0 = Sunday, 6 = Saturday

      // Skip Sunday (always excluded)
      if ($day_of_week != 0) {
        // If include_saturday is false, skip Saturday (only count Mon-Fri)
        if ($include_saturday || $day_of_week != 6) {
          $working_days++;
        }
      }

      $current->modify('+1 day');
    }

    return $working_days;
  }
  public function calculateOrderAmount($package_id, $start_date, $end_date, $include_saturday = false)
  {
    // Get package price per day
    $package = $this->from('packages')->where('id', $package_id)->fetch();
    if (!$package) {
      return 0;
    }

    $price_per_day = floatval($package['price']);

    // Calculate working days
    $working_days = $this->calculateWorkingDays($start_date, $end_date, $include_saturday);

    // Calculate total amount
    $total_amount = $price_per_day * $working_days;

    return $total_amount;
  }
  public function getCurrency()
  {
    // Get currency from config table, default to 'usd' if not set
    $config = $this->from('config')->where('keyname', 'currency')->fetch();
    if ($config && !empty($config['value'])) {
      return strtolower($config['value']);
    }
    return 'usd'; // Default currency
  }
  public function get_all_credentials()
  {
    $result = [];
    $kv = [];
    $rows = $this->from('credentials')->select(null)->select('_key,_value')->fetchAll();
    foreach ($rows as $row) {
      $k = isset($row['_key']) ? $row['_key'] : null;
      $v = isset($row['_value']) ? $row['_value'] : null;
      if ($k !== null) {
        $kv[$k] = $v;
      }
    }
    return $kv;
  }

  /**
   * Get setting value by key from settings table
   */
  public function getSettingValue(string $key): ?string
  {
    $row = $this->from('settings')->where('_key', $key)->fetch();
    return $row ? $row['_value'] : null;
  }

  /**
   * Get credential value by key from credentials table
   */
  protected function getCredentialValue(string $key): ?string
  {
    $row = $this->from('credentials')->where('_key', $key)->fetch();
    return $row ? $row['_value'] : null;
  }

  /**
   * Increment a numeric setting and return new value
   */
  protected function incrementSetting(string $key, int $step = 1): int
  {
    $current = $this->getSettingValue($key);
    $currentVal = $current ? intval($current) : 0;
    $newVal = $currentVal + $step;

    $existing = $this->from('settings')->where('_key', $key)->fetch();
    if ($existing) {
      $this->update('settings')->set(['_value' => (string) $newVal])->where('_key', $key)->execute();
    } else {
      $this->insertInto('settings')->values(['_key' => $key, '_value' => (string) $newVal, 'description' => 'Auto-incremented value'])->execute();
    }

    return $newVal;
  }

  /**
   * Log third-party API service calls.
   */
  protected function logServiceCall(string $table, ?string $logoId, string $url, string $method, mixed $request, mixed $response, ?int $httpCode): void
  {
    try {
      $values = [
        'datetime' => date('Y-m-d H:i:s'),
        'logo_id' => $logoId,
        'url' => $url,
        'method' => $method,
        'request' => is_scalar($request) ? (string) $request : json_encode($request),
        'response' => is_scalar($response) ? (string) $response : json_encode($response),
        'http_code' => $httpCode
      ];
      $this->insertInto($table)->values($values)->execute();
    } catch (\Throwable $e) {
      error_log("Failed to log service call to $table: " . $e->getMessage());
    }
  }

  /**
   * Get color details by code/manufacturer (Legacy Helper)
   */
  public function getcolorDetails(string $code, string $manufacturer = ''): ?array
  {
    $query = $this->from('color_list')
      ->select('color_list.*')
      ->select('color_manufacturer.code as manufacturecode')
      ->leftJoin('color_manufacturer ON color_list.manufacturer = color_manufacturer.name');

    if ($manufacturer === '') {
      // Legacy behavior: try to match CONCAT(manufacturecode, '-', code)
      $query->where("CONCAT(color_manufacturer.code, '-', color_list.code) = ?", $code);
    } else {
      $query->where("color_list.code = ?", $code)
        ->where('color_list.manufacturer = ?', $manufacturer);
    }

    $result = $query->fetch();
    return $result ?: null;
  }
}
