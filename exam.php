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
    
    if (!function_exists("ldap_connect")) {
        error_log("ldap not installed... failing ldap authentication");
        return $user; // failed!!
    }   
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
    // $user['common_name'] = $m['cn'][0];
    $user['matricola'] = array_get($m, 'unipistudentematricola',[$ldapUser])[0];
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

function my_explode($separator, $string) {
    // explodes string into array and removes empty strings
    return array_filter(explode($separator, $string), function($x) {return $x !== '';});
}

function my_timestamp($date, $time) {
    if ($date === null || $time === null) return null;
    try {
        error_log("my_timestamp ".json_encode($date). " ". json_encode( $time));
        return DateTime::createFromFormat("j.n.Y H:i", $date . ' ' . $time)->getTimestamp();
    } catch (Exception $e) {
        error_log("invalid date/ time $date $time\n");
        return null;
    }
}

function my_xml_get($xml, $key, $default=null) {
    if (isset($xml[$key])) return (string) $xml[$key];
    return $default;
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
    
        $root = $this->xml_root;
        $this->secret = my_xml_get($root, 'secret', '');
        $this->admins = my_explode(',', my_xml_get($root, 'admins', ''));
        $this->course = my_xml_get($root, 'course');
        $this->name = my_xml_get($root, 'name');
        $this->auth_methods = my_explode(',', my_xml_get($root, 'auth_methods', 'ldap'));
        
        $this->date = my_xml_get($root, 'date');
        $this->time = my_xml_get($root, 'time');
        $this->end_time = my_xml_get($root, 'end_time');
        $this->duration_minutes = (int) my_xml_get($root, 'duration_minutes');

        $this->timestamp = my_timestamp($this->date, $this->time);
        $this->end_timestamp = my_timestamp($this->date, $this->end_time);
        
        $this->storage_path = my_xml_get($root, 'storage_path', $this->exam_id);
        if (substr($this->storage_path, 0, 1) !== '/') {
            // relative path
            $this->storage_path = __DIR__ . '/' . $this->storage_path;
        }
        if (!is_dir($this->storage_path)) {
            mkdir($this->storage_path, 0777, True);
        }
        
        $this->now = time();
        $this->is_open = True;
        $this->start_timestamp = null;
        $this->seconds_to_start = 0;
        if ($this->timestamp && $this->now < $this->timestamp) {
            $this->is_open = False;
            $this->seconds_to_start = $this->timestamp - $this->now;
        } 
        error_log("END_TIMESTAMP {$this->end_timestamp} {$this->now}");
        if ($this->end_timestamp && $this->now > $this->end_timestamp) {
            $this->is_open = False;
        }
        error_log("IS OPEN {$this->is_open}");
    }
    
    function is_admin($matricola) {
        return in_array($matricola, $this->admins);
    }

    function start() {
        $this->start_timestamp = $this->now;
    }

    function compose_for($matricola, $options=[]) {
        $this->matricola = $matricola;
        $this->storage_filename = $this->storage_path . "/" . $matricola . ".jsons";

        if ($this->start_timestamp === null) {
            // bisogna controllare se il compito e' gia' partito
            $start = $this->read('start', False); // attenzione: non bisogna permettere di rifare uno start, prendiamo l'ultimo
            if ($start === null) {
                $this->start_timestamp = null;
            } else {
                $this->start_timestamp = $start['timestamp'];
                if ($this->timestamp !== null && $this->timestamp > $this->start_timestamp) {
                    /*
                    * se l'ultimo start e' anteriore alla data di inizio il compito e' stato riproposto
                    */
                    $this->start_timestamp = null;
                }
            }
        } else {
            // e' stato chiamato $this->start() per avviare immediatamente il compito
        }

        $this->seconds_to_finish = null;
        if ($this->start_timestamp !== null) {
            // calcola il tempo che manca alla fine del compito
            // se specificato un tempo massimo calcola in base all'inizio dello svolgimento

            if ($this->duration_minutes !== null) {
                $this->seconds_to_finish = $this->start_timestamp + $this->duration_minutes * 60 - $this->now;
            }

            // se c'e' un tempo massimo di consegna calcola il tempo rimanente 
            // non deve superare il tempo massimo
            if ($this->end_timestamp !== null) {
                $s = $this->end_timestamp - $this->now;
                if ($this->seconds_to_finish === null || $this->seconds_to_finish > $s) {
                    $this->seconds_to_finish = $s;
                }
            }

            if ($this->seconds_to_finish !== null && $this->seconds_to_finish <=0) {
                $this->seconds_to_finish = 0;
            }
        }

        $this->options = $options;
        $this->rand = new MyRand($this->secret . '_' . $this->matricola);
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
            if (array_get($this->options, 'variants')) { // don't shuffle
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
            if (array_get($this->options, 'variants')) { // show all variants
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
                    if (array_get($this->options,'solutions')) { // show solutions
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
        if (!isset($this->storage_filename)) throw new Exception('Call Exam::login before Exam::write');
        # error_log("writing to file " . $this->storage_filename);
        $fp = fopen($this->storage_filename, "at");
        if ($fp === False) throw new Exception('Cannot write file ' + $this->storage_filename);
        $timestamp = date(DATE_ATOM);
        fwrite($fp, json_encode([
            'timestamp' => $timestamp,
            'user' => $user,
            $action => $object
            ]) . "\n");
        fclose($fp);
        return $timestamp;
    } 

    function read($action,$first=True) {
        if (!isset($this->storage_filename)) throw new Exception('Call Exam::login before Exam::write');
        // error_log(">>>reading " . $this->storage_filename . "\n");
        $found = null;
        if (!file_exists($this->storage_filename)) return $found;
        $fp = fopen($this->storage_filename, "rt");
        if ($fp === False) throw new Exception('Cannot read file ' . $this->storage_filename);
        while(True) {
            $line = fgets($fp);
            if ($line === False) break; // EOF
            $line = trim($line);
            if ($line === "") continue;
            $obj = json_decode($line, True);
            // error_log(">>line: " . json_encode($obj) . "\n");
            if (isset($obj[$action])) {
                $found = $obj;
                if ($first) break; // find first line 
            }
        }
        fclose($fp);
        if ($found !== null) $found['timestamp'] = DateTime::createFromFormat(DateTime::ATOM,$found['timestamp'])->getTimestamp();
        return $found;
        }
}

function get_compito($exam, $user) {
    $response = [];
    $response['user'] = $user;
    $response['matricola'] = $exam->matricola;
    $response['timestamp'] = $exam->timestamp;
    $response['end_timestamp'] = $exam->end_timestamp;
    $response['end_time'] = $exam->end_time;
    $response['duration_minutes'] = $exam->duration_minutes;
    $response['seconds_to_start'] = $exam->seconds_to_start;
    $response['is_open'] = $exam->is_open;
    $response['ok'] = True;

    if ($exam->start_timestamp !== null || array_get($user, 'is_admin')) {
        // lo studente ha iniziato (e forse anche finito) l'esame
        // oppure siamo admin
        // in tal caso possiamo mostrare il compito

        $exam->write($user, "compito", [
            'text' => $exam->text,
            'answers' => $exam->answers
            ]);
        $response['text'] = $exam->text;
        $response['seconds_to_finish'] = $exam->seconds_to_finish;

        $answers = [];
        $obj = $exam->read('submit', False);
        if ($obj !== null) {
            foreach($obj['submit'] as $item) {
                $answers[$item['form_id']] = $item['answer'];
            }
        }
        if (count($answers) === 0) {
            $answers = null; // empty array is otherwise encoded as array instead of dictionary
        }
        $response['answers'] = $answers;
    }

    return $response;
}

function submit($exam, $user) {
    $response = [];
    $response['user'] = $user;
    $response['ok'] = False;
    if (!$user['authenticated']) {
        $response['message'] = "utente non autenticato!";
        return;
    }

    $matricola = $user['matricola'];
    $is_admin = array_get($user, 'is_admin');

    if ($is_admin) {
        $matricola = array_get($_POST['matricola'], $matricola);
    }

    $exam->compose_for($matricola);

    if (!$is_admin) {
        if (!$exam->is_open) {
            $response['message'] = "il compito è chiuso, non è possibile inviare le risposte";
            return $response;
        }
        if ($exam->seconds_to_finish !== null && $exam->seconds_to_finish <= 0) {
            $response['message'] = "tempo scaduto, non è più possibile inviare le risposte.";
            return $response;
        }
    }
    
    foreach($exam->answers as &$answer) {
        $key = 'answer_' . $answer['form_id'];
        $val = array_get($_POST, $key);
        if ($val === null) {
            error_log(json_encode($_POST));
            $response['message'] = 'richiesta non valida';
            return $response;
        }
        $answer['answer'] = $val;
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
        foreach($exam->auth_methods as $auth) {
            error_log("trying " . $auth);
            if ($auth === 'ldap') $user = authenticate();
            else if ($auth === 'fake') $user = fake_authenticate();
            if ($user['authenticated']) break;
        }
        if (!$user['authenticated']) return error_response("utente non riconosciuto");   
        
        $user['is_admin'] = $exam->is_admin(array_get($user, 'matricola'));

        $action = array_get($_POST, 'action');
        
        if ($action === 'reload' || $action === 'login') {
            $matricola = $user['matricola'];
            if ($user['is_admin']) {
                $matricola = array_get($_POST, 'matricola', $matricola);
                $options = [
                    'solutions' => (array_get($_POST, 'solutions') === 'true'),
                    'variants' => (array_get($_POST, 'variants') === 'true')
                ];
                $exam->compose_for($matricola, $options);            
            } else {
                // non admins cannot inspect variations
                $exam->compose_for($matricola);
            }
            return get_compito($exam, $user);
        }
        
        if ($action === 'start') {
            $matricola = $user['matricola'];
            $exam->start();
            $exam->compose_for($matricola);
            $exam->write($user, 'start', True); /* segnamo l'inizio del compito */
            return get_compito($exam, $user);
        }
        
        if ($action === 'submit') {
            return submit($exam, $user);
        }

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
      <h3><?php echo("{$exam->date}"); if ($exam->time) echo(" ore {$exam->time}"); ?></h3>
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
            cambia matricola: <input id="set_matricola" disabled><br />
        </div>
        <div>
            Si suggerisce di usare notazioni semplici ma chiare per scrivere le formule nelle caselle di risposta
            (non scrivere in \(\LaTeX\)).
            Ad esempio per descrivere la formula \(\frac{\sqrt{\frac 3 4 \pi+1}}{\sqrt[3]{c_1} + x^{2+e}}\)
            si scriva: 
            <pre>
                sqrt(3/4*pi + 1) / (sqrt^3(c_1) + x^(2+e))
            </pre>
            Per scrivere \(\alpha, +\infty, \mathbb R, \forall, \exists, &lt;, &gt;, \ge, \le\) si scriva:
            <pre>
                alfa, +oo, R, per ogni, esiste, &lt;, &gt;, >=, <=
            </pre>
        </div>
        <div>
            <p>
                Si compilino le caselle con le risposte e si prema il pulsante di invio man mano che vengono risolti (o modificati) gli esercizi.
                I tempi di risposta devono coincidere con i tempi utilizzati per lo svolgimento.
                Allo scadere del tempo verrà considerata l'ultima versione inviata. 
                Nei 15 minuti dopo lo scadere del tempo si dovrà inviare copia degli appunti dove risultino tutti i passaggi svolti.
            </p>
            <p><b>legenda:</b>
            <span style='color:black'>&#9632;</span> risposta non data --
            <span style='color:red'>&#9632;</span> risposta non inviata --
            <span style='color:green'>&#9632;</span> risposta inviata            
            </p>
        </div>
        <div>            
            <div id="timer"></div>
            <button id="submit" hidden>invia risposte</button>
            <div id="response" style="color:blue"></div>
        </div>
        <div id="exercises">
        </div>
    </div>
  </body>
</html>

<?php endif; ?>