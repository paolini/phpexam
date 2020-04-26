<?php

function array_get($array, $key, $default=null) {
    if (isset($array[$key])) return $array[$key];
    return $default;
}

function authenticate() {
    $ldapHost = "ldaps://idm2.unipi.it";
    $ldapPort = "636";	// (default 389)
    $ldapUser  = ""; // ldap User (rdn or dn)
    $ldapPassword = "";
    $user = [
        'authenticated' => false
    ];
    
    global $successMessage, $errorMessage, $authenticated;
    
    $ldapConnection = ldap_connect($ldapHost, $ldapPort);
    
    if (!$ldapConnection) {
        $user['error'] = "Non riesco a collegarmi al server di autenticazione (ldap)";
        return $user;
    }

    if (isset($_POST["user"]) && $_POST["user"] != "") {
        $user['user'] = $_POST['user'];
        $ldapUser = addslashes(trim($_POST["user"]));
    } else {
        $user['error'] = "Inserisci il nome utente";
        return $user;
    }

    if (isset($_POST["password"]) && $_POST["password"] != "") {
        $ldapPassword = addslashes(trim($_POST["password"]));
    } else {
        $user['error'] = "Inserisci password";
        return $user;
    }

    // binding to ldap server
    ldap_set_option($ldapConnection, LDAP_OPT_PROTOCOL_VERSION, 3) or die('Unable to set LDAP protocol version');
    ldap_set_option($ldapConnection, LDAP_OPT_REFERRALS, 0);

    $role = 'dm';
    $base_dn = 'ou=people,dc=unipi,dc=it';

    $bind_dn = 'uid=' . $ldapUser . ',' . $base_dn;
    $ldapbind = @ldap_bind($ldapConnection, $bind_dn, $ldapPassword);

    // verify binding
    if (!$ldapbind) {
        $user['error'] = "Credenziali non valide!";
        ldap_close($ldapConnection);
        return $user;
    }

    $results = ldap_search($ldapConnection, "dc=unipi,dc=it", "uid=" . $ldapUser);
    
    if (ldap_count_entries($ldapConnection, $results) != 1) {
        $user['error'] = "Utente non trovato!";
        ldap_close($ldapConnection);
        return $user;
    }

    $matches = ldap_get_entries($ldapConnection, $results);
    $m = $matches[0];
    $user['nome'] = $m['givenname'][0];
    $user['cognome'] = $m['sn'][0];
    $user['common_name'] = $m['cn'][0];
    $user['matricola'] = $role == 'student' ? $m['unipistudentematricola'][0] : $ldapUser;
    $user['authenticated'] = true;
    ldap_close($ldapConnection);

    return $user;
}

function fake_authenticate() {
    $user = [
        'authenticated' => false
    ];

    if (isset($_POST["user"]) && $_POST["user"] != "") {
        $user['matricola'] = $_POST['user'];
        $user['user'] = $_POST['user'];
    } else {
        $user['error'] = "Inserisci il nome utente";
        return $user;
    }
    $user['nome'] = $_POST["user"];
    $user['cognome'] = $_POST["user"];
    $user['authenticated'] = true;
    $user['is_fake'] = true;

    return $user;
}

function my_int32($x) {
    # Get the 32 least significant bits.
    return 0xFFFFFFFF & $x;
}

class MyRand {
    /*
    Random numbers which depends on seed in a portable repeatable way
    Mersenne Twister 19937 generator
    See MT19937 for algorithm used
    */

    function __construct($seed_string) {
        $seed = 0;
        $hash = hash("md5", $seed_string, $raw_output=true);
        for ($i=0;$i<4;$i++) {
            $seed = $seed * 256 + ord(substr($hash,$i,1));
        }
        $this->seed = $seed; // non verra' modificato

        // error_log("rand init $seed\n");

        // Initialize the index to 0
        $this->index = 624;
        $this->mt = array_fill(0, 624, 0);
        $this->mt[0] = $seed;  // Initialize the initial state to the seed
        for($i = 1; $i< 624; $i++) {
            $this->mt[$i] = my_int32(1812433253 * ($this->mt[$i-1] ^ ($this->mt[$i-1] >> 30)) + $i);
        }
    }

    function extract_number() {
        if ($this->index >= 624) $this->twist();

        $y = $this->mt[$this->index];
        // Right shift by 11 bits
        $y = $y ^ ($y >> 11);
        //Shift y left by 7 and take the bitwise and of 2636928640
        $y = $y ^ (($y << 7) & 2636928640);
        // Shift y left by 15 and take the bitwise and of y and 4022730752
        $y = $y ^ (($y << 15) & 4022730752);
        // Right shift by 18 bits
        $y = $y ^ ($y >> 18);

        $this->index = $this->index + 1;
        return my_int32($y);
    }

    function twist() {
        for ($i=0; $i<624; $i++) {
            // Get the most significant bit and add it to the less significant
            // bits of the next number
            $y = my_int32(($this->mt[$i] & 0x80000000) +
                       ($this->mt[($i + 1) % 624] & 0x7fffffff));
            $this->mt[$i] = $this->mt[($i + 397) % 624] ^ ($y >> 1);

            if (($y % 2) !== 0) $this->mt[$i] = $this->mt[$i] ^ 0x9908b0df;
            }
        $this->index = 0;
    }

    function random($n) {
        $r = $this->extract_number();
        $r = $r % $n;
        //        error_log("random $r\n");
        return $r;
    }

    function shuffled($iterable) {
        $r = [];
        $count = 0;
        foreach($iterable as $x) {
            $count++;
            array_splice($r, $this->random($count), 0, [$x]);
        }
        return $r;
    }

    function rand_digits($digits) {
        return pow(10,($digits-1)) + $this->random(pow(10,$digits) - pow(10,($digits-1)));
    }
}


if (false) {
    // test random numbers:
    foreach(["ciccio", "pippo", "pluto", "gigio", "luca", "mario", "giovanni"] as $seed) {
        $rand = new MyRand($seed);
        echo("seed $seed {$rand->random(2)}\n");
        foreach([1,2,3,4,5] as $i) {
            echo("{$rand->random(10)}\n");
        }
    }
    die();
}

function recurse_push(&$lst, $lst_or_item, $type) {
    if ($lst === null) throw("ghufd");
    if (isset($lst_or_item['type']) && $lst_or_item['type'] === $type) {
        array_push($lst, $lst_or_item);
    } else if (is_array($lst_or_item)){
        foreach($lst_or_item as $item) {
            recurse_push($lst, $item, $type);
        }
    }
}

// errors depending on the contents of the XML file
// we should report to the user
class ExamError extends Exception {
    public function __toString() {
        return "{$this->message}";
    }
}


class Exam {
    function __construct($xml_filename, $exam_id) {
        $this->exam_id = $exam_id;
        libxml_use_internal_errors(true);
        $this->xml_root = simplexml_load_file($xml_filename);
        if (!$this->xml_root) {
            $message = "XML parsing errors:";
            foreach(libxml_get_errors() as $error){
                $message .= "\nline {$error->line}: {$error->message}";
            }
            libxml_clear_errors();
            throw new Exception($message);
        }
    
        $this->secret = array_get($this->xml_root, 'secret', '');
        $this->admins = array_filter(explode(',', array_get($this->xml_root, 'admins', '')), function($x) {return $x !== '';});
        $this->storage_path = array_get($this->xml_root, 'storage_path', $this->exam_id);
        if (substr($this->storage_path, 0, 1) !== '/') {
            // relative path
            $this->storage_path = __DIR__ . '/' . $this->storage_path;
        }
        $this->course = array_get($this->xml_root, 'course');
        $this->name = array_get($this->xml_root, 'name');
        $this->date = array_get($this->xml_root, 'date');
    }

    function login($user) {
        $this->is_admin = in_array($user['matricola'], $this->admins);
        $this->matricola = $user['matricola'];    
        $this->logged_in = true;
    }
    
    function compose($options) {
        if (!isset($this->logged_in)) throw new Exception('Call Exam::login before Exam::compose');
        $this->options = $options;
        $matricola = $this->matricola;
        if ($this->is_admin) { // admin può chiedere il compito di altri
            $matricola = array_get($options, 'matricola', $matricola);
        }
        $this->rand = new MyRand($this->secret . '_' . $matricola);
        $this->answers = [];
        $this->exercise_count = 0;
        $this->variant_count = 0;
        $this->text = $this->recurse_parse($this->xml_root);
    }

    function recurse_parse($xml) {
        $name = $xml->getName();
    //    echo("recurse_parse $name \n");
        if ($name === 'exam') {
            $obj = ['type' => $name];
            $obj['exercises'] = [];
            foreach($xml->children() as $child) {
                $item = $this->recurse_parse($child);
                recurse_push($obj['exercises'], $item, 'exercise');
            }
            return $obj;
        }
        if ($name === 'shuffle') {
            $lst = [];
            $children = $xml->children();
            if ($this->is_admin && array_get($this->options, 'variants')) { // only admin: don't shuffle
                // nop
            } else {
                $children = $this->rand->shuffled($children);
            }
            foreach($children as $child) {
                array_push($lst, $this->recurse_parse($child));
            }
            return $lst;
        }
        if ($name === 'variants') {
            if ($this->is_admin && array_get($this->options, 'variants')) { // only admin: show all variants
                $lst = [];
                $fix_count = $this->exercise_count;
                foreach($xml->children() as $child) {
                    $this->variant_count ++;
                    $this->exercise_count = $fix_count; // fix exercise number in variants!
                    array_push($lst, $this->recurse_parse($child));
                }
                $this->variant_count = 0;
                return $lst;
            }
            $count = $xml->count();
            $n = $this->rand->random($count);
            return $this->recurse_parse($xml->children()[$n]);
        }
        if ($name === 'exercise') {
            $obj = ['type' => $name];
            $this->exercise_count ++;
            $obj['number'] = "{$this->exercise_count}";
            if ($this->variant_count>0) {
                $obj['number'] .= ".{$this->variant_count}";
            }
            $obj['statement'] = trim($xml->__toString());
            $obj['questions'] = [];
            foreach($xml->children() as $item) {
                $x = $this->recurse_parse($item);
                recurse_push($obj['questions'], $x, 'question');
            }
            return $obj;
        }
        if ($name === 'question') {
            $obj = ['type' => $name];
            $count = count($this->answers);
            $form_id = "x_$count";
            $obj['form_id'] = $form_id;
            $obj['statement'] = trim((string) $xml);
            $answer = [];
            $id = (string) $xml['id'];
            $answer['id'] = $id;
            $answer['form_id'] = $form_id;
            foreach($xml->children() as $child) {
                if ($child->getName() === 'answer') {
                    $answer['solution'] = trim((string) $child);
                    if ($this->is_admin && array_get($this->options,'solutions')) { // only admin: show solutions
                        $obj['solution'] = trim((string) $child);
                    }
                }
            }
            array_push($this->answers, $answer);
            return $obj;
        }
        throw new ExamError("elemento XML inatteso <$name>");
    }

    function write($user, $action, $object) {
        if (!is_dir($this->storage_path)) {
            mkdir($this->storage_path);
        }
        $filename = $this->storage_path . "/" . $user['matricola'] . ".jsons";
        # error_log("writing to file " . $filename);
        $fp = fopen($filename, "at") or die("Cannot write file!");
        $timestamp = date(DATE_ATOM);
        fwrite($fp, json_encode([
            'timestamp' => $timestamp,
            'user' => $user,
            $action => $object
            ]) . "\n");
        fclose($fp);
        return $timestamp;
    } 
    
}

function get_login($exam, $user) {
    $exam->login($user);
    $response = [
        'user' => $user,
        'is_admin' => $exam->is_admin,
        'ok' => True
    ];
    return $response;
}

function get_compito($exam, $user, $options) {
    $exam->login($user);
    $exam->compose($options);
    $response = [];
    $response['user'] = $user;
    $exam->write($user, "compito", [
        'is_admin' => $exam->is_admin,
        'text' => $exam->text,
        'answers' => $exam->answers
        ]);
    $response['text'] = $exam->text;
    $response['is_admin'] = $exam->is_admin;
    $response['ok'] = True;
    return $response;
}

function submit($exam, $user) {
    $exam->login($user);
    $exam->compose($user, []);
    $response = [];
    $response['user'] = $user;
    $response['ok'] = False;
    if (!$user['authenticated']) {
        $response['message'] = "utente non autenticato!";
        return;
    }

    foreach($exam->answers as &$answer) {
        $key = 'answer_' . $answer['form_id'];
        if (!isset($_POST[$key])) {
            error_log(json_encode($_POST));
            $response['message'] = 'richiesta non valida';
            return $response;
        }
        $answer['answer'] = $_POST[$key];
    }
    // error_log("risposte: " . json_encode($compito->risposte));
    
    $response['timestamp'] = $exam->write($user, "submit", $exam->answers);
    $response['ok'] = True;
    $response['message'] = "risposte inviate!";
    return $response;
}

function error_response($error_message) {
    return ['ok' => False, 'error' => $error_message];
}

function serve($exam) {
    try {
        $user = fake_authenticate();
        if (!$user['authenticated']) return error_response("utente non autenticato");

        $action = array_get($_POST, 'action');
        
        if ($action === "login") return get_login($exam, $user);

        if ($action === "reload") {
            $options = [
                'matricola' => array_get($_POST, 'matricola'),
                'solutions' => (array_get($_POST, 'solutions') === 'true'),
                'variants' => (array_get($_POST, 'variants') === 'true')
            ];
            return get_compito($exam, $user, $options);
        }  
        
        if ($action === 'submit') return submit($exam, $user);

        return error_response("richiesta non valida");
    } catch (ExamError $e) {
        return error_response("$e");
    }
}

// EXECUTION STARTS HERE

$exam_id = array_get($_GET,'id');

if (!preg_match('/^[A-Za-z0-9\-]+$/', $exam_id)) {
    header('HTTP/1.1 404 Not Found');
    echo("indirizzo non valido");
    exit();
}

$exam_filename = __DIR__ . '/' . $exam_id . '.xml';
if (!file_exists($exam_filename)) {
    error_log("Cannot open file $exam_filename\n");
    header('HTTP/1.1 404 Not Found');
    echo("esame non trovato");
    echo(__FILE__ . " " . __DIR__);
    exit();
}

try {
    $exam = new Exam($exam_filename, $exam_id);
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Error');
    echo("<html><body><pre>{$e->getMessage()}</pre></html></body>");
    exit();
}
?>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
<?php
header('Content-Type: application/json');
echo json_encode(serve($exam));
?>

<?php else: ?>

<!DOCTYPE html>
<html>
  <head>
    <title><?php echo("{$exam->course}: {$exam->name}")?></title>
    <script type="text/javascript" async src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.7/MathJax.js?config=TeX-MML-AM_CHTML"></script>
    <script src="https://code.jquery.com/jquery-3.5.0.min.js" integrity="sha256-xNzN2a4ltkB44Mc/Jz3pT4iU1cmeR0FkXs4pru/JxaQ=" crossorigin="anonymous"></script>
    <script>
        <?php echo file_get_contents(__DIR__ . '/exam.js')?> 
    </script>
  </head>
  <body data-rsssl=1 data-rsssl=1>
      <h2><?php echo("{$exam->course}"); ?></h2>
      <h3><?php echo("{$exam->name}"); ?></h3>
      <h3><?php echo("{$exam->date}"); ?></h3>
    <h3 id="error" style="color:red" hidden></h3>
    <div id="auth">
        <table style="display:inline-block;">
            <tr>
                <td>User</td>
                <td><input id="user" type="text" name="user" maxlength="50"></td>
            </tr>
            <tr>
                <td>Password</td>
                <td><input id="password" type="password" name="password" value="" maxlength="50"></td>
            </tr>
            <tr>
                <td colspan="2"><button id="login">entra</button></td>
            </tr>
        </table>
    </div>
    <div id="text" style="max-width:50em" hidden>  
        <div id="user_div">
            <b>Cognome:</b> <span id="cognome"></span> <br />
            <b>Nome:</b> <span id="nome"></span> <br />
            <b>Matricola:</b> <span id="matricola"></span> <br />
        </div>
        <div id="admin" hidden>
            <b>riservato agli amministratori:</b><br/>
            mostra soluzioni: <input id="show_solutions" type="checkbox" checked><br />
            mostra varianti:  <input id="show_variants" type="checkbox" checked><br />
            cambia matricola: <input id="set_matricola"><br />
        </div>
        <div id="exercises">
        </div>
        <button id="submit" hidden>invia risposte</button>
        <div id="response" style="color:blue"></div>
    </div>
  </body>
</html>

<?php endif; ?>