<?php

date_default_timezone_set('Europe/Rome');

session_start();

function my_log($msg) 
{
    $fp = fopen(__DIR__ . '/var/phpexam.log', 'at');
    if ($fp !== null) {
        $timestamp = date(DATE_ATOM);
        fwrite($fp, "$timestamp $msg\n");
        fclose($fp);
    }
}

function myErrorHandler($errno, $errstr, $errfile, $errline)
{
    my_log("$errno $errstr $errfile:$errline");
    return False;
}

// set to the user defined error handler
$old_error_handler = set_error_handler("myErrorHandler");

// myErrorHandler(42, "hello there", "file", "line");

function array_get($array, $key, $default=null) {
    if (isset($array[$key])) return $array[$key];
    return $default;
}

// Terrible hack because the SSL certificate on the Unipi side is not
// validated by a publicy available CA.
putenv("LDAPTLS_REQCERT=never");

function authenticate($username, $password) {
    $ldapHost = "ldaps://idm2.unipi.it";
    $ldapPort = "636";	// (default 389)
    $ldapUser  = $username; // ldap User (rdn or dn)
    $ldapPassword = $password;

    if ($username == "") return null;
    
    if (!function_exists("ldap_connect")) {
        throw new Exception("ldap not installed... failing ldap authentication");
    }   
    $ldapConnection = ldap_connect($ldapHost, $ldapPort);
    
    if (!$ldapConnection) {
        throw new Exception("Non riesco a collegarmi al server di autenticazione (ldap)");
    }

    $ldapUser = addslashes(trim($ldapUser));
    $ldapPassword = addslashes(trim($ldapPassword));

    // binding to ldap server
    ldap_set_option($ldapConnection, LDAP_OPT_PROTOCOL_VERSION, 3) or die('Unable to set LDAP protocol version');
    ldap_set_option($ldapConnection, LDAP_OPT_REFERRALS, 0);

    $base_dn = 'ou=people,dc=unipi,dc=it';

    $bind_dn = 'uid=' . $ldapUser . ',' . $base_dn;
    $ldapbind = @ldap_bind($ldapConnection, $bind_dn, $ldapPassword);
    
    // verify binding
    if (!$ldapbind) {
        my_log("credenziali non valide");
        ldap_close($ldapConnection);
        return null;
    }
    
    $results = ldap_search($ldapConnection, "dc=unipi,dc=it", "uid=" . $ldapUser);

    if (ldap_count_entries($ldapConnection, $results) != 1) {
        my_log("utente non trovato");
        ldap_close($ldapConnection);
        return null;
    }

    $matches = ldap_get_entries($ldapConnection, $results);
    $m = $matches[0];
    ldap_close($ldapConnection);
    return [
        'nome' => $m['givenname'][0],
        'cognome' => $m['sn'][0],
        // $user['common_name'] = $m['cn'][0];
        'matricola' => array_get($m, 'unipistudentematricola',[$ldapUser])[0]
    ];
}

function fake_authenticate($username, $password) {
    if ($username == '') return null;

    return [
        'matricola' => $username,
        'user' => $username,
        'nome' => $username,
        'cognome' => $username,
        'is_fake' => true
    ];
}

function get_user($exam) {
    $action = array_get($_POST, 'action');
    $user = null;
    
    if ($action == 'login') {
        $username = array_get($_POST, 'user');
        $password = array_get($_POST, 'password');
        // my_log("get_user " . $username);
        foreach($exam->auth_methods as $auth) {
            $u = null;
            if ($auth === 'ldap') $u = authenticate($username, $password);
            else if ($auth === 'fake') $u = fake_authenticate($username, $password);
            else throw new Exception("invalid authentication method $auth");
            if ($u !== null) {
                $user = $u;
                $_SESSION['user'] = $user;
                my_log("USER LOGIN $auth OK: " . json_encode($user));
                break;
            }
        }
    } else {
        $user = array_get($_SESSION, 'user', null);
    }

    if ($user != null) {
        $user['is_admin'] = $exam->is_admin(array_get($user, 'matricola'));
    }
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

function recurse_push(&$lst, $lst_or_item, $type) {
    if ($lst === null) throw("internal error #4483");
    if ($lst_or_item === null) {
        // discard
    } else if (isset($lst_or_item['type']) && $lst_or_item['type'] === $type) {
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
        // error_log("my_timestamp ".json_encode($date). " ". json_encode( $time));
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

function my_xml_get_bool($xml, $key, $default=null) {
    if (isset($xml[$key])) {
        $val = (string) $xml[$key];
        if ($val === '1' || strtolower($val) === 'true') return True;
        if ($val === '0' || strtolower($val) === 'false') return False;
        throw new ParseError("Not a valid boolean value $val for key $key");
    };
    return $default;
}

function interpolate($template, $student) {
    if ($student !== null) {
        foreach($student as $key => $val) {
            $template = str_replace('{{ student[\'' . $key . '\'] }}', $val, $template);
        }
    }
    return $template;
}

function interpolate_mustache($template, $student) {
    require __DIR__ . '/vendor/autoload.php';
    $m = new Mustache_Engine(array('entity_flags' => ENT_QUOTES));
    return $m->render($template, ['student' => $student]);
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
        $this->now = time();
        $this->secret = my_xml_get($root, 'secret', '');
        $this->admins = my_explode(',', my_xml_get($root, 'admins', ''));
        $this->course = my_xml_get($root, 'course');
        $this->name = my_xml_get($root, 'name');
        $this->auth_methods = my_explode(',', my_xml_get($root, 'auth_methods', 'ldap'));
        
        $this->date = my_xml_get($root, 'date');
        if ($this->date == 'everyday') {
            $this->date = date("j.n.Y",$this->now);
        }
        $this->time = my_xml_get($root, 'time');
        if ($this->date == null) $this->time = null;
        $this->end_time = my_xml_get($root, 'end_time');
        $this->end_upload_time = my_xml_get($root, 'end_upload_time');
        $this->duration_minutes = (int) my_xml_get($root, 'duration_minutes');
        $this->can_be_repeated = my_xml_get_bool($root, "can_be_repeated", False);
        $this->show_solutions = my_xml_get_bool($root, 'publish_solutions', False);
        $this->show_variants = False;
        $this->publish_text = my_xml_get_bool($root, "publish_text", False);
        $this->show_instructions = my_xml_get_bool($root, "show_instructions", True);
        $this->show_legenda = my_xml_get_bool($root, "show_legenda", True);
        $this->use_mustache = my_xml_get_bool($root, "use_mustache", False);
        
        $this->timestamp = my_timestamp($this->date, $this->time); // inizio della prova
        $this->end_timestamp = my_timestamp($this->date, $this->end_time); // termine massimo
        $this->end_upload_timestamp = my_timestamp($this->date, $this->end_upload_time); // tempo massimo per il caricamento dei files
        
        $this->storage_path = my_xml_get($root, 'storage_path', $this->exam_id);
        if (substr($this->storage_path, 0, 1) !== '/') {
            // relative path
            $this->storage_path = __DIR__ . '/' . $this->storage_path;
        }
        if (!is_dir($this->storage_path)) {
            // todo: controllare se da' errore!
            mkdir($this->storage_path, 0777, True);
        }

        $this->csv_delimiter = my_xml_get($root, 'csv_delimiter', ",");

        $this->students = null;
        $students_csv_filename = my_xml_get($root, 'students_csv');
        if ($students_csv_filename !== null) {
            if (substr($students_csv_filename, 0, 1) !== '/') {
                $students_csv_filename = __DIR__ . '/' . $students_csv_filename;
            }
            $this->load_students_csv($students_csv_filename);
        }
        $this->check_students = my_xml_get_bool($root, "check_students", False);

        $this->instructions_html = null;
        
        $this->is_open = True;
        $this->start_timestamp = null;
        $this->seconds_to_start = 0;
        if ($this->timestamp && $this->now < $this->timestamp) {
            // il compito deve ancora iniziare
            $this->is_open = False;
            $this->seconds_to_start = $this->timestamp - $this->now;
        }
        $this->start_timeline = null; 
        if ($this->timestamp !== null && $this->duration_minutes > 0) {
            // puoi iniziare entro questo istante senza penalita
            $this->start_timeline = $this->end_timestamp - 60*$this->duration_minutes; 
        }
        $this->seconds_to_start_timeline = 0;
        if ($this->start_timeline && $this->now < $this->start_timeline) {
            // puoi iniziare senza penalita'
            $this->seconds_to_start_timeline = $this->start_timeline - $this->now;
        }
        // error_log("END_TIMESTAMP {$this->end_timestamp} {$this->now}");
        if ($this->end_timestamp && $this->now > $this->end_timestamp) {
            // il tempo e' scaduto
            $this->is_open = False;
        }
        // error_log("IS OPEN {$this->is_open}");

        $this->upload_is_open = True;
        if ($this->end_upload_timestamp !== null && $this->now > $this->end_upload_timestamp) {
            $this->upload_is_open = False;
        }

        $this->IP = getenv('HTTP_CLIENT_IP')?:
            (getenv('HTTP_X_FORWARDED_FOR')?:
            (getenv('HTTP_X_FORWARDED')?:
            (getenv('HTTP_FORWARDED_FOR')?:
            (getenv('HTTP_(FORWARDED')?:
            getenv('REMOTE_ADDR')))));
        $this->http_user_agent = array_get($_SERVER, 'HTTP_USER_AGENT');
    }

    function load_students_csv($csv_filename) {
        $h = fopen($csv_filename, "r");
        if ($h === False) {
            throw new ParseError("Impossibile aprire il file $csv_filename");
        }
        $headers = null;
        $students = [];
        $ident_column = null;
        while (($line = fgetcsv($h, 1000, $this->csv_delimiter)) !== FALSE) {
            if ($line === [ null ]) continue;
            if ($headers === null) {
                $headers = $line;
                for ($i=0; $i<count($headers); $i++) {
                    if (strtolower($headers[$i]) == 'matricola') {
                        $ident_column = $i;
                    break;
                    }
                }
                if ($ident_column === null) throw new ParseError("Mi aspetto 'matricola' come intestazione di una colonna");
                continue;
            }
            $student = [];
            for($i=0;$i < count($headers); $i++) {
                $student[$headers[$i]] = $line[$i];
            }
            $students[$line[$ident_column]] = $student;
        }
        fclose($h);
        $this->students = $students;
    }

    function is_admin($matricola) {
        return in_array($matricola, $this->admins);
    }

    function compute_countdowns() {
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
    }

    function start($user) {
        $this->start_timestamp = $this->now;
        $this->write($user, 'start', True); /* segnamo l'inizio del compito */
        $this->compute_countdowns();
    }

    function compose_for($matricola) {
        my_log("compose_for $matricola timestamp " . $this->timestamp);
        $this->matricola = $matricola;
        $this->storage_filename = $this->storage_path . "/" . $matricola . ".jsons";
        $this->student = null;
        if ($this->students !== null && $this->matricola) {
            $this->student = array_get($this->students, $this->matricola);
        }

        $root = $this->xml_root;
        $this->instructions = null;
        $this->instructions_html = null;
        foreach ($root as $child) {
            if ($child->getName() === 'instructions' && $this->show_instructions) {
                $this->instructions = (string) $child;
                if (my_xml_get($child, 'engine') === 'mustache') {
                    $this->instructions = interpolate_mustache($this->instructions, $this->student);
                } else {
                    $this->instructions = interpolate($this->instructions, $this->student);
                }
                if (my_xml_get($child, 'format') === 'html') { 
                    $this->instructions_html = $this->instructions;
                }
                else $this->instructions_html = htmlspecialchars($this->instructions);
            }
        }    
        // error_log("ISTRUZIONI " . $this->instructions_html);        

        // determina l'istante di inizio effettivo del compito 
        // per questo studente
        $start_list = $this->read('start'); // attenzione: non bisogna permettere di rifare uno start, prendiamo l'ultimo
        if (count($start_list) == 0) {
            $this->start_timestamp = null;
        } else {
            $start = $start_list[count($start_list) - 1];
            $this->start_timestamp = $start['timestamp'];
            if ($this->timestamp !== null && $this->timestamp > $this->start_timestamp) {
                /*
                * se l'ultimo start e' anteriore alla data di inizio il compito e' stato riproposto
                * e possiamo iniziare nuovamente
                */
                $this->start_timestamp = null;
            }
        }

        $this->compute_countdowns();

        $this->rand = new MyRand($this->secret . '_' . $this->matricola);
        $this->answers = [];
        $this->exercise_count = 0;
        $this->variant_count = 0;
        $this->exercise_id = null;
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
            if ($this->show_variants) { // don't shuffle
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
            if ($this->show_variants) { // show all variants
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
            $this->exercise_id = my_xml_get($xml, 'id');
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
            $answer['exercise_id'] = $this->exercise_id;
            $answer['form_id'] = $form_id;
            foreach($xml->children() as $child) {
                if ($child->getName() === 'answer') {
                    $answer['solution'] = trim((string) $child);
                    if ($this->show_solutions) {
                        $obj['solution'] = trim((string) $child);
                    }
                }
            }
            array_push($this->answers, $answer);
            return $obj;
        }
        if ($name === 'instructions') return null;
        if (substr($name,-1) === '_') return null;
        throw new ExamError("elemento XML inatteso <$name>");
    }

    function write($user, $action, $object) {
        if (!isset($this->storage_filename)) throw new Exception('Call Exam::login before Exam::write');
        // error_log("writing to file " . $this->storage_filename);
        $fp = fopen($this->storage_filename, "at");
        if ($fp === False) throw new Exception('Cannot write file ' + $this->storage_filename);
        $timestamp = date(DATE_ATOM);
        fwrite($fp, json_encode([
            'timestamp' => $timestamp,
            'IP' => $this->IP,
            'http_user_agent' => $this->http_user_agent,
            'user' => $user,
            $action => $object
            ]) . "\n");
        fclose($fp);
        return $timestamp;
    } 

    function read($action) {
        if (!isset($this->storage_filename)) throw new Exception('Call Exam::login before Exam::write');
        // error_log(">>>reading " . $this->storage_filename . "\n");
        $lst = [];
        if (!file_exists($this->storage_filename)) return $lst;
        $fp = fopen($this->storage_filename, "rt");
        if ($fp === False) throw new Exception('Cannot read file ' . $this->storage_filename);
        while(True) {
            $line = fgets($fp);
            if ($line === False) break; // EOF
            $line = trim($line);
            if ($line === "") continue;
            $obj = json_decode($line, True);
            if (isset($obj[$action])) {
                $obj['timestamp'] = DateTime::createFromFormat(DateTime::ATOM,$obj['timestamp'])->getTimestamp();
                array_push($lst, $obj);
            }
        }
        fclose($fp);
        return $lst;
        }

    function csv_response($fp_handle) {
        $dir = new DirectoryIterator($this->storage_path);
        foreach ($dir as $fileinfo) {
            $filename = $fileinfo->getFilename();
            $pathinfo = pathinfo($filename);
            if ($pathinfo['extension'] === 'jsons') {
                $matricola = $pathinfo['filename'];
                $this->compose_for($matricola);
                $fp = fopen($this->storage_filename, "rt");
                if ($fp === False) throw new Exception('Cannot read file ' . $this->storage_filename);
                while(True) {
                    $line = fgets($fp);
                    if ($line === False) break; // EOF
                    $line = trim($line);
                    if ($line === '') continue;
                    $obj = json_decode($line, True);
                    if (isset($obj['submit']) || isset($obj['start'])) {
                        // error_log("object " . json_encode($obj));
                        $row = [
                            $obj['timestamp'],
                            $obj['user']['matricola'],
                            $obj['user']['cognome'],
                            $obj['user']['nome']
                        ];
                        if (isset($obj['submit'])) {
                            $submit = $obj['submit'];
                            if (count($submit) !== count($this->answers)) {
                                // number of answer have been modified after submission
                                array_push($row,'mismatch');
                                // throw new Exception("data mismatch");
                            } else {
                                array_push($row,'submit');
                            }
                            for ($i=0; $i < count($submit) ; $i ++) {
                                $submit[$i]['exercise_id'] = $this->answers[$i]['exercise_id'];
                            }
                            usort($submit, function($a, $b){ return $a['id'] < $b['id']?-1:1;});
                            foreach($submit as $ans) {
                                array_push($row, $ans['exercise_id'], $ans['id'], $ans['answer']);
                            }
                        } else {
                            array_push($row,'start');
                        }
                        fputcsv($fp_handle, $row);
                    }
                }
            }
        }
    }

    function get_student_list() {
        $list = [];
        $dir = new DirectoryIterator($this->storage_path);
        foreach ($dir as $fileinfo) {
            $filename = $fileinfo->getFilename();
            $pathinfo = pathinfo($filename);
            if ($pathinfo['extension'] === 'jsons') {
                $matricola = $pathinfo['filename'];
                $filename = $this->storage_path . '/' . $filename;
                $fp = fopen($filename, "rt");
                if ($fp === False) throw new Exception('Cannot read file ' . $filename);
                while(True) {
                    $line = fgets($fp);
                    if ($line === False) break; // EOF
                    $line = trim($line);
                    if ($line === '') continue;
                    $obj = json_decode($line, True);
                    // if (!isset($obj['submit'])) continue;
                    $user = array_get($obj, 'user');
                    if ($user !== null) {
                        array_push($list, $user);
                        break;
                    }
                }
                fclose($fp);
            }
        }
        usort($list, function($a, $b) {
            return $a['cognome']<$b['cognome']?-1:($a['cognome']>$b['cognome']?1:($a['nome']<$b['nome']?-1:1));
        });
        return $list;
    }

    function pdf_filename_is_valid($filename) {
        $prefix = $this->matricola . '_';
        return substr($filename, 0, strlen($prefix)) == $prefix &&
            substr($filename, -4) == ".pdf" &&
            strpos($filename,"/") === false && 
            strpos($filename, "\\") === false && 
            strpos($filename, " ") === false;
    }

    function get_files_list() {
        $files = [];
        if ($handle = opendir($this->storage_path)) {
            while (false !== ($file = readdir($handle))) {
                if ($this->pdf_filename_is_valid($file)) {
                    array_push($files, $file);
                }
            }
            closedir($handle);
        }
        sort($files);
        return $files;
    }
}

function get_compito($exam, $user) {
    $response = [];
    $response['user'] = $user;
    $response['matricola'] = $exam->matricola;
    $response['cognome'] = array_get($user, 'cognome');
    $response['nome'] = array_get($user, 'nome');
    $response['timestamp'] = $exam->timestamp;
    $response['end_timestamp'] = $exam->end_timestamp;
    $response['end_time'] = $exam->end_time;
    $response['duration_minutes'] = $exam->duration_minutes;
    $response['seconds_to_start'] = $exam->seconds_to_start;
    $response['seconds_to_start_timeline'] = $exam->seconds_to_start_timeline;
    $response['is_open'] = $exam->is_open;
    $response['upload_is_open'] = $exam->upload_is_open;
    $response['can_be_repeated'] = $exam->can_be_repeated;
    $response['ok'] = True;
    $response['instructions_html'] = $exam->instructions_html;  
    $response['file_list'] = $exam->get_files_list();

    if (!array_get($user, 'is_admin')) { // verifica che lo studente sia iscritto
        if ($exam->check_students && $exam->student===null) {
            $response['ok'] = False;
            $response['error'] = "Non risulti iscritto a questo esame (matricola ". $exam->matricola . ")";
            return $response;
        }
    }

    if ($exam->start_timestamp !== null || array_get($user, 'is_admin') || $exam->publish_text) {
        // lo studente ha iniziato (e forse anche finito) l'esame
        // oppure siamo admin
        // oppure è stato dichiarato un testo pubblico
        // in tal caso possiamo mostrare il compito
        if ($exam->is_open && !array_get($user, 'is_admin')) {
            // logga gli accessi durante il compito
            $exam->write($user, "compito", [
                'text' => $exam->text,
                'answers' => $exam->answers
                ]);
            }
        $response['text'] = $exam->text;
        $response['seconds_to_finish'] = $exam->seconds_to_finish;

        $answers = [];
        $submissions = $exam->read('submit');
        if (count($submissions) > 0) {
            $obj = $submissions[count($submissions) - 1];
            foreach($obj['submit'] as $item) {
                $answers[$item['form_id']] = $item['answer'];
            }
            $user = array_get($obj, 'user');
            if ($user !== null) {
                $response['cognome'] = array_get($user, 'cognome');
                $response['nome'] = array_get($user, 'nome');
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
    if ($user === null) {
        $response['message'] = "utente non autenticato!";
        return;
    }

    $matricola = $user['matricola'];
    $is_admin = array_get($user, 'is_admin');

    if ($is_admin) {
        $matricola = array_get($_POST, 'matricola', $matricola);
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

function request_path()
{
    $request_uri = explode('/', trim($_SERVER['REQUEST_URI'], '/'));
    $script_name = explode('/', trim($_SERVER['SCRIPT_NAME'], '/'));
    $parts = array_diff_assoc($request_uri, $script_name);
    if (empty($parts))
    {
        return '/';
    }
    $path = implode('/', $parts);
    if (($position = strpos($path, '?')) !== FALSE)
    {
        $path = substr($path, 0, $position);
    }
    return $path;
}

// errors depending on the contents of the XML file
// we should report to the user
class ResponseError extends Exception {
    public function __toString() {
        return "{$this->message}";
    }
}

/*******************************
 * EXECUTION STARTS HERE       *
 *******************************/

// error_log("****************");

$exam_id = array_get($_GET,'id');

if ($exam_id === null) $exam_id = request_path();

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
    // echo(__FILE__ . " " . __DIR__);
    exit();
}

try {
    $exam = new Exam($exam_filename, $exam_id);
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Error');
    echo("<html><body><pre>{$e->getMessage()}</pre></html></body>");
    exit();
}

$action = array_get($_POST, 'action');
$user = get_user($exam);
if ($action == 'login' && $user != null) {
    $action = 'load';
}
?>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
<?php
try {
    try {
        my_log("POST ".$action." ".$exam->exam_id." ".array_get($user, 'user', 'user not authenticated'));
        // error_log("POST ".$action." ".$exam->exam_id." ".array_get($user, 'user', 'user not authenticated'));
        $response = null;
        if ($user == null) {
            if ($action == 'login') throw new ResponseError("utente non riconosciuto o password non valida");
            throw new ResponseError("utente non autenticato");
        }
        if ($action === 'logout') {
            session_destroy();   
            $user = null;
            $response = ['ok' => True];
        } else if ($action === 'reload' || $action === 'load') {
            $matricola = $user['matricola'];
            if ($user['is_admin']) {
                $matricola = array_get($_POST, 'matricola', '');
                if (array_get($_POST, 'solutions') === 'true') $exam->show_solutions = True;
                if (array_get($_POST, 'variants') === 'true') $exam->show_variants = True;
                $exam->compose_for($matricola);  
            } else {
                // non admins cannot inspect variations
                $exam->compose_for($matricola);
            }
            $response = get_compito($exam, $user);
        } else if ($action === 'start') {
            $matricola = $user['matricola'];
            $exam->compose_for($matricola);
            if (!$exam->is_open) throw new ResponseError("l'esame non è aperto");
            if ($exam->check_students && $exam->student === null) throw new ResponseError("lo studente non è iscritto");
            if ($exam->start_timestamp && !$exam->can_be_repeated) {
                // l'esame era già stato avviato!
                throw new ResponseError("l'esame è già stato avviato");
            }
            $exam->start($user);
            $response = get_compito($exam, $user);
        } else if ($action === 'submit') {
            $response = submit($exam, $user);
        } else if ($action === 'pdf_upload') {
            if (! $exam->upload_is_open) {
                throw new ResponseError("non è più possibile caricare files");
            }
            $file = $_FILES['file'];
            if ($file['error'] > 0) {
                my_log("php file upload error code: " . $file['error']);
                my_log("file upload size: " . $file['size']);
                $error = "Errore nel caricamento file (error code: " . $file['error'] . ")";
                if ($file['error'] == UPLOAD_ERR_INI_SIZE) $error = "il file è troppo grosso";
                throw new ResponseError($error);
            } 
            error_log("PDF_UPLOAD: " . json_encode($file));
            $matricola = $user["matricola"];
            $exam->compose_for($matricola);
            $filename = $exam->storage_path . "/" . $matricola . "_" . $now->format('c') . ".pdf";
            $r = move_uploaded_file($file["tmp_name"], $filename);
            if ($r) {
                $hash = md5_file($filename);
                my_log("upload " . $filename. " md5 hash " . $hash);
                $dir = $exam->get_files_list();
                $response = [
                    'ok' => True,
                    'dir' => $dir
                    ];
            } else {
                my_log("upload " . $filename . " failed");
                $response = [
                    'ok' => False,
                    'error' => 'upload fallito'
                ];
            }
        } else if ($action === 'pdf_delete') {
            if (!$exam->upload_is_open) {
                throw new ResponseError("non è più possibile rimuovere files");
            }
            $matricola = $user['matricola'];
            $exam->compose_for($matricola);
            $filename = array_get($_POST, 'filename');
            my_log("REMOVE FILE " . $filename);
            if ($exam->pdf_filename_is_valid($filename)) {
                $filename = $exam->storage_path . "/" . $filename;
                unlink($filename);
                $dir = $exam->get_files_list();
                $response = [
                    'ok' => True,
                    'dir' => $dir
                ];
            } else {
                $response = [
                    'ok' => False,
                    'error' => 'nome file non valido'
                ];
            }
        } else if ($action === 'pdf_download') {
            $matricola = $user['matricola'];
            if ($user['is_admin']) {
                $matricola = array_get($_POST, 'matricola', $matricola);
            }
            $exam->compose_for($matricola);
            $filename = array_get($_POST, 'filename');
            if ($exam->pdf_filename_is_valid($filename)) {
                $filename = $exam->storage_path . "/" . $filename;
                header("Content-type: application/pdf");
                // Send the file to the browser.
                readfile($filename);
            } else {
                $response = [
                    'ok' => False,
                    'error' => 'non autorizzato'
                ];
            }
        } else if ($action === 'csv_download') {
            if (!$user['is_admin']) throw new ResponseError("user not authorized");
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="'.$exam_id.'.csv";');
            $fp = fopen('php://output', 'w');
            $exam->csv_response($fp);
            fclose($fp);
        } else if ($action === 'get_students') {
            $response = [
                'ok' => True,
                'students' => $exam->get_student_list()
            ];
        } else {
            error_log("richiesta non valida");
            throw new ResponseError("richiesta non valida");
        }
        if ($response !== null) {
            header('Content-Type: application/json');
            echo json_encode($response);
        }
    } catch (ExamError $e) {
        throw new ResponseError("$e");
    }
} catch (ResponseError $e) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => False, 'error' => "$e"]);
} catch (Exception $e) {
    my_log($e);
    throw($e);
}
?>

<?php else: ?> 

<?php 
my_log("GET ".$exam->exam_id);
?>

<!DOCTYPE html>
<html>
  <head>
    <title><?php echo("{$exam->course}: {$exam->name}")?></title>
    <script type="text/javascript" async src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.7/MathJax.js?config=TeX-MML-AM_CHTML"></script>
    <script src="https://code.jquery.com/jquery-3.5.0.min.js" integrity="sha256-xNzN2a4ltkB44Mc/Jz3pT4iU1cmeR0FkXs4pru/JxaQ=" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.2.0/jspdf.umd.min.js" integrity="sha512-YnVU8b8PyEw7oHtil6p9au8k/Co0chizlPltAwx25CMWX6syRiy24HduUeWi/WpBbJh4Y4562du0CHAlvnUeeQ==" crossorigin="anonymous"></script>
    <script>
        <?php echo file_get_contents(__DIR__ . '/exam.js')?> 
    </script>
    <style>
span.fill {
    display: block;
    overflow: hidden;
    padding-right: 5px;
    padding-left: 10px;
}

input.fill {
    width: 100%;
}

span.left {
    float: left;
}
    </style>
  </head>
  <body data-rsssl=1 data-rsssl=1>
      <h2><?php echo("{$exam->course}"); ?></h2>
      <h3><?php echo("{$exam->name}"); ?></h3>
      <h3><?php echo("{$exam->date}"); if ($exam->time) echo(" ore {$exam->time}"); ?></h3>
      <?php if (in_array('fake', $exam->auth_methods)) echo ("<h2 style='color:red'>configurazione insicura!</h2>")?>
      <!--pre>
      <?php echo $exam->timestamp; ?>
      <?php echo $exam->end_timestamp; ?>
      <?php echo $exam->now; ?>
      <?php echo $exam->duration_minutes ?>
      </pre-->
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
            <b>Matricola:</b> <span id="matricola"></span> <button id="logout">logout</button><br />        </div>
        <div id="admin" hidden>
            <b>riservato agli amministratori:</b><br/>
            mostra soluzioni: <input id="show_solutions" type="checkbox" checked><br />
            scegli matricola: <input id="set_matricola">  <select id="select_student"></select> <br />
            <button id="csv_download">download csv</button><br />
        </div>
        <div id="instructions"></div>
        <?php if ($exam->show_legenda): ?>
        <div id="legenda">
            <p><b>legenda:</b>
            <span style='color:black'>&#9632;</span> risposta non data,
            <span style='color:red'>&#9632;</span> risposta non inviata, 
            <span style='color:green'>&#9632;</span> risposta inviata            
            </p>
        </div>
        <?php endif; ?>
        <div>            
            <div id="timer"></div>
            <button id="submit" hidden>invia risposte</button> 
            <div id="response" style="color:blue"></div>
        </div>
        <div id="exercises">
        </div>
        <div id="upload">
            <p class="upload">Quando hai finito il compito puoi inviare le scansioni dello svolgimento. 
            Premi sul pulsante [scegli files] per caricare un singolo file in formato PDF 
            oppure una foto di ogni pagina. 
            Le foto vanno ruotate, se necessario, prima di premere 
            sul pulsante [carica file] che provvederà a generare un unico file PDF.
            </p>
            <p>Elenco files caricati: </p>
            <ul id="upload_list">
            </ul>
            <div class="upload">
                <input id="upload_input_id" type="file" multiple>
                <button id="upload_pdf_id">carica file</button>
                <div id="upload_div_id">
                </div>
            </div>
        </div>
    </div>
  </body>
</html>

<?php endif; ?>