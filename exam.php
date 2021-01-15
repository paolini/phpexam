<?php

date_default_timezone_set('Europe/Rome');

// server should keep session data for AT LEAST 5 hour
ini_set('session.gc_maxlifetime', 5 * 60 * 60);
// each client should remember their session id for EXACTLY 5 hour
session_set_cookie_params(5 * 60 * 60);
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

function implode_ints($array) {
    return implode(".", array_map(
        function($n){return strval($n+1);}, 
        $array));
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

class Text {
    function __construct($exam, $matricola, $show_variants) {
        $this->exam = $exam;
        $this->matricola = $matricola;
        $this->student = null;
        if ($exam->students !== null && $matricola) {
            $this->student = array_get($exam->students, $matricola);
        }

        $this->instructions = null;
        $this->instructions_html = null;
        $instructions = $exam->tree['instructions'];
        if ($instructions !== null && $exam->show_instructions) {
            if ($instructions['engine'] === 'mustache') {
                $this->instructions = interpolate_mustache($instructions['text'], $this->student);
            } else {
                $this->instructions = interpolate($instructions['text'], $this->student);
            }
            if ($instructions['format'] === 'html') { 
                $this->instructions_html = $this->instructions;
            } else $this->instructions_html = htmlspecialchars($this->instructions);
        }
        $this->instructions = $instructions;
        // error_log("ISTRUZIONI " . $this->instructions_html);        

        $this->show_variants = $show_variants;
        $this->rand = new MyRand($this->exam->secret . '_' . $this->matricola);
        $tree = $this->exam->tree;
        assert($tree['type'] === 'exam');
        $this->exercises = [];
        $this->form_id = 0; // incremental number used for input id in html form
        $context = new stdClass();
        $context->exercise_count = 0;
        foreach($tree['children'] as $child) {
            $lst = $this->recurse_compose_exercises($child, $context);
            foreach ($lst as $exercise) {
                array_push($this->exercises, $exercise);
            }
        }

        // determina l'istante di inizio effettivo del compito 
        // per questo studente
        $submissions = $exam->read_submissions($matricola);
        if (count($submissions) == 0) {
            $this->start_timestamp = null;
        } else {
            $this->start_timestamp = $submissions[0]['timestamp'];
            if ($exam->timestamp !== null && $exam->timestamp > $this->start_timestamp) {
                /*
                * se l'ultimo start e' anteriore alla data di inizio il compito e' stato riproposto
                * e possiamo iniziare nuovamente
                */
                $this->start_timestamp = null;
            }
        }

        // inserisce tabella delle modifiche alle risposte
        $this->submissions = [];
        $last_submit = null;
        $last_user = null;
        foreach($submissions as $submission) {
            $row = [
                'timestamp' => $submission['timestamp'],
                'seconds' => $submission['timestamp'] - $this->start_timestamp,
                'answers' => null,
            ];
            $last_user = array_get($submission, 'user');
            $last_submit = array_get($submission, 'submit');
            if ($last_submit !== null) {
                $row['answers'] = [];
                foreach($last_submit as $item) {
                    array_push($row['answers'], [
                        'id' => array_get($item, 'id'),
                        'form_id' => array_get($item, 'form_id'),
                        'exercise_id' => array_get($item, 'exercise_id'),
                        'answer' => array_get($item, 'answer')]);
                }
            }
            array_push($this->submissions, $row);
        }
        if ($last_user !== null) {
            $this->cognome = array_get($last_user, 'cognome');
            $this->nome = array_get($last_user, 'nome');
        }
        if ($last_submit !== null) {
            // inserisci l'ultima risposta nel testo del compito
            foreach($last_submit as $item) {
                foreach($this->exercises as &$exercise) {
                    foreach ($exercise['questions'] as &$question) {
                        if ($question['form_id'] == $item['form_id']) {
                            $question['answer'] = $item['answer'];
                        }
                    }
                }
            }
        }


        // compute countdowns
        $this->seconds_to_finish = null;
        if ($this->start_timestamp !== null) {
            // calcola il tempo che manca alla fine del compito
            // se specificato un tempo massimo calcola in base all'inizio dello svolgimento

            if ($this->exam->duration_minutes !== null) {
                $this->seconds_to_finish = $this->start_timestamp + $this->exam->duration_minutes * 60 - $this->exam->now;
            }

            // se c'e' un tempo massimo di consegna calcola il tempo rimanente 
            // non deve superare il tempo massimo
            if ($this->exam->end_timestamp !== null) {
                $s = $this->exam->end_timestamp - $this->exam->now;
                if ($this->seconds_to_finish === null || $this->seconds_to_finish > $s) {
                    $this->seconds_to_finish = $s;
                }
            }

            if ($this->seconds_to_finish !== null && $this->seconds_to_finish <=0) {
                $this->seconds_to_finish = 0;
            }
        }

    }    

    private function recurse_compose_exercises($tree, $context) {
        $name = $tree['type'];
        if ($name === 'shuffle') {
            $lst = [];
            $children = $tree['children'];
            if ($this->show_variants) { // don't shuffle
                // nop
            } else {
                $children = $this->rand->shuffled($children);
            }
            foreach($children as $child) {
                $exercises = $this->recurse_compose_exercises($child, $context);
                foreach ($exercises as $exercise) {
                    array_push($lst, $exercise);
                }
            }
            return $lst;
        }
        if ($name === 'variants') {
            if ($this->show_variants) { // show all variants
                $lst = [];
                foreach($tree['children'] as $child) {
                    $exercises = $this->recurse_compose_exercises($child, $context);
                    foreach ($exercises as $exercise) {                  
                        array_push($lst, $exercise);
                    }
                }
                return $lst;
            } else {
                $count = count($tree['children']);
                $n = $this->rand->random($count);
                return $this->recurse_compose_exercises($tree['children'][$n], $context);
            }
        }
        if ($name === 'exercise') {
            $questions = [];
            foreach($tree['questions'] as $question) {
                $form_id = "x_{$this->form_id}";
                $this->form_id ++;
                array_push($questions,
                    [
                        'type' => 'question',
                        'id' => $question['id'],
                        'form_id' => $form_id,
                        'statement' => $question['statement'],
                        'solution' => $question['solution'],
                        'answer' => null,
                    ]
                );
            }
            
            $exercise = [
                'number' => $this->show_variants ? $tree['id'] : ++$context->exercise_count,
                'statement' => $tree['statement'],
                'questions' => $questions     
            ];
            return [$exercise];
        }
    }

    function response_for($user) {
        $text = $this;
        $exam = $text->exam;

        $cognome = "";
        $nome = "";
        if (count($text->submissions)) {
            $cognome = $text->cognome;
            $nome = $text->nome;
        } else if (array_get($user, 'matricola') == $text->matricola) {
            $cognome = array_get($user , 'cognome');
            $nome = array_get($user, 'nome');
        }

        $response = [
            'user' => $user,
            'matricola' => $text->matricola,
            'cognome' => $cognome,
            'nome' => $nome,
            'timestamp' => $exam->timestamp,
            'end_timestamp' => $exam->end_timestamp,
            'end_time' => $exam->end_time,
            'duration_minutes' => $exam->duration_minutes,
            'seconds_to_start' => $exam->seconds_to_start,
            'seconds_to_start_timeline' => $exam->seconds_to_start_timeline,
            'is_open' => $exam->is_open,
            'upload_is_open' => $exam->upload_is_open,
            'can_be_repeated' => $exam->can_be_repeated,
            'ok' => True,
            'instructions_html' => $text->instructions_html,  
            'file_list' => $exam->get_files_list($text->matricola),
        ];
    
        if (!array_get($user, 'is_admin')) { // verifica che lo studente sia iscritto
            if ($exam->check_students && $text->student===null) {
                $response['ok'] = False;
                $response['error'] = "Non risulti iscritto a questo esame (matricola ". $text->matricola . ")";
                return $response;
            }
        }
    
        if ($text->start_timestamp !== null || array_get($user, 'is_admin') || $exam->publish_text) {
            // lo studente ha iniziato (e forse anche finito) l'esame
            // oppure siamo admin
            // oppure è stato dichiarato un testo pubblico
            // in tal caso possiamo mostrare il compito
            my_log("SHOWING text ". $exam->exam_id . " for " . $text->matricola . " to " . $user['matricola']);
            $show_solutions = array_get($user, 'is_admin', false) || ($exam->publish_text && $exam->publish_solutions);
            $exercises = [];
            foreach($text->exercises as $exercise) {
                $questions = [];
                foreach($exercise['questions'] as $question) {
                    array_push($questions, [
                        'id' => $question['id'],
                        'form_id' => $question['form_id'],
                        'statement' => $question['statement'],
                        'solution' => ($show_solutions ? $question['solution'] : null),
                        'answer' => $question['answer'],
                    ]);
                }
                array_push($exercises, [
                    'number' => $exercise['number'],
                    'statement' => $exercise['statement'],
                    'questions' => $questions,
                ]);
            }
            $response['text'] = ['exercises' => $exercises];
            $response['seconds_to_finish'] = $text->seconds_to_finish;
            $response['submissions'] = $text->submissions;
    
            if ($exam->is_open && !array_get($user, 'is_admin')) {
                // logga gli accessi durante il compito
                $exam->write($text->matricola, $user, "compito", [
                    'exercises' => $response['text']['exercises']
                    ]);
                }
        } else {
            my_log("PREPARING exam ". $exam->exam_id . " for " . $user['matricola']);
        }
    
        return $response;
    }    
}

function xml_recurse_parse($xml, $context=null) {
    $name = $xml->getName();

    if ($name === 'exam' && $context === null) {
        $context = new stdClass();
        $context->level = 'exam'; // exam or exercise
        $context->path = [0]; // first available object counter (ramified) [2,1] means 3.2
        $context->exercise_ids = []; // used exercise_ids
        $context->question_ids = []; // used question_ids

        $lst = [];
        $instructions = null;
        foreach($xml->children() as $child) {
            $item = xml_recurse_parse($child, $context);
            if ($item === null) continue; // to be ignored
            if ($item['type'] == 'instructions') {
                if ($instructions !== null) throw new ExamError("<instructions> compare più volte");
                $instructions = $item;
                continue;
            }
            array_push($lst, $item);
        }
        return [
            'type' => $name, 
            'children' => $lst, 
            'instructions' => $instructions];
    }
    if ($context === null) throw new ExamError(("elemento XML <'$name'> invalido, mi aspettavo <exam>"));
    
    if ($name == 'shuffle') {
        $lst = [];
        foreach($xml->children() as $child) {
            $item = xml_recurse_parse($child, $context);
            if ($item === null) continue; // to be ignored
            array_push($lst, $item);
        }
        return ['type' => $name, 'children' => $lst];
    }
    
    if ($name === 'variants') {
        $lst = [];
        array_push($context->path, 0); // aggiunge una ramificazione
        foreach($xml->children() as $child) {  
            $item = xml_recurse_parse($child, $context);
            if ($item === null) continue; // to be ignored
            array_push($lst, $item);
        }
        array_pop($context->path);
        $context->path[count($context->path)-1]++; // increase ramification counter
        return ['type' => $name, 'children' => $lst];
    }
    
    if ($name === 'exercise' && $context->level === 'exam') {
        $id = my_xml_get($xml, 'id', implode_ints($context->path));
        if (in_array($id, $context->exercise_ids)) throw new ExamError("l'id '$id' dell'esercizio è stato usato più volte");
        array_push($context->exercise_ids, $id);
        $obj = [
            'type' => $name,
            'id' => $id,
            'statement' => trim($xml->__toString()),
            'questions' => [],
        ];
        $context->level = 'exercise';
        array_push($context->path, 0); // aggiunge ramificazione per le risposte
        foreach($xml->children() as $item) {
            $item = xml_recurse_parse($item, $context);
            if ($item === null) continue;
            $item['exercise_id'] = $id;
            array_push($obj['questions'], $item);
        }
        array_pop($context->path);
        $context->path[count($context->path)-1] ++; // increase ramification counter
        $context->level = 'exam';
        return $obj;
    }
    
    if ($name === 'question' && $context->level === 'exercise') {
        $id = my_xml_get($xml, 'id', implode_ints($context->path));
        if (in_array($id, $context->question_ids)) throw new ExamError("l'id '$id' della domanda è usato più volte");
        $context->path[count($context->path)-1] ++; // increase ramification counter 
        array_push($context->question_ids, $id);
        $obj = [
            'type' => $name,
            'id' => $id,
            'statement' => trim((string) $xml),
            'solution' => null,
        ];
        foreach($xml->children() as $child) {
            $child_name = $child->getName();
            if ($child_name === 'answer') {
                if ($obj['solution'] !== null) throw new ExamError("elemento <answer> multiplo nella stessa <question>");
                $obj['solution'] = trim((string) $child);
            } else if (substr($child_name,-1) !== '_') {
                throw new ExamError("elemento XML inatteso <$child_name> in <$name>");
            }
        }
        return $obj;
    }
    
    if ($name === 'instructions' && $context->level === 'exam') {
        return [ 
            'type' => $name,
            'text' => (string) $xml,
            'engine' => my_xml_get($xml, 'engine'),
            'format' => my_xml_get($xml, 'format')
        ];
    }
    
    if (substr($name,-1) === '_') return null;
    
    throw new ExamError("elemento XML inatteso <$name> in <{$context['level']}>");
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
        $this->publish_solutions = my_xml_get_bool($root, 'publish_solutions', False);
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

        $this->tree = xml_recurse_parse($root);
        // $this->answers = $this->tree['answers'];    
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
                $student[$headers[$i]] = $i < count($line)? $line[$i] : "";
            }
            $students[$line[$ident_column]] = $student;
        }
        fclose($h);
        $this->students = $students;
    }

    function is_admin($matricola) {
        return in_array($matricola, $this->admins);
    }

    function storage_filename($matricola) {
        return $this->storage_path . "/" . $matricola . ".jsons";
    }

    function write($matricola, $user, $action, $object) {
        $storage_filename = $this->storage_filename($matricola);
        // error_log("writing to file " . $storage_filename);
        $fp = fopen($storage_filename, "at");
        if ($fp === False) throw new Exception('Cannot write file ' + $storage_filename);
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

    function read_submissions($matricola) {
        $storage_filename = $this->storage_filename($matricola);
        // error_log(">>>reading " . $storage_filename . "\n");
        $lst = [];
        if (!file_exists($storage_filename)) return $lst;
        $fp = fopen($storage_filename, "rt");
        if ($fp === False) throw new Exception('Cannot read file ' . $storage_filename);
        while(True) {
            $line = fgets($fp);
            if ($line === False) break; // EOF
            $line = trim($line);
            if ($line === "") continue;
            $obj = json_decode($line, True);
            if (isset($obj['timestamp'])) {
                $obj['timestamp'] = DateTime::createFromFormat(DateTime::ATOM,$obj['timestamp'])->getTimestamp();
            }
            if (isset($obj['start'])) {
                $lst = [];
                array_push($lst, $obj);
            } else if (isset($obj['submit'])) {
                array_push($lst, $obj);
            }
        }
        fclose($fp);
        return $lst;
        }

    function csv_response($fp_handle, $with_log=true) {
        $students = $this->get_student_list();
        $headers = ["timestamp", "minuti", "matricola", "cognome", "nome"];
        fputcsv($fp_handle, $headers);
        foreach ($students as $student) {
            $text = new Text($this, $student['matricola'], true);
            $submissions = $text->submissions;
            if (!$with_log) $submissions = array_slice($submissions, -1);
            foreach($submissions as $submission) {
                $row = [
                    $submission['timestamp'],
                    round($submission['seconds'] / 60),
                    $text->matricola,
                    $text->cognome,
                    $text->nome,
                ];
                $answers = array_get($submission, 'answers');
                if ($answers != null) {
                    usort($answers, function($a, $b) {return strcmp($a['id'], $b['id']);});            
                    foreach ($answers as $answer) {
                        array_push($row, $answer['exercise_id'], $answer['id'], $answer['answer']);
                    }
                }
                fputcsv($fp_handle, $row);
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

    function pdf_filename_is_valid($filename, $matricola) {
        $prefix = $matricola . '_';
        return substr($filename, 0, strlen($prefix)) == $prefix &&
            substr($filename, -4) == ".pdf" &&
            strpos($filename,"/") === false && 
            strpos($filename, "\\") === false && 
            strpos($filename, " ") === false;
    }

    function get_files_list($matricola) {
        $files = [];
        if ($handle = opendir($this->storage_path)) {
            while (false !== ($file = readdir($handle))) {
                if ($this->pdf_filename_is_valid($file, $matricola)) {
                    array_push($files, $file);
                }
            }
            closedir($handle);
        }
        sort($files);
        return $files;
    }
}


function submit($exam, $user) {
    if ($user === null) return ['ok' => false, 'message' => "utente non autenticato!" ];

    $matricola = $user['matricola'];
    $is_admin = array_get($user, 'is_admin');

    if ($is_admin) $matricola = array_get($_POST, 'matricola', $matricola);

    $text = new Text($exam, $matricola, false);

    if (!$is_admin) {
        if (!$exam->is_open) return ['ok' => false, 'message' => "il compito è chiuso, non è possibile inviare le risposte"];
        if ($text->seconds_to_finish !== null && $text->seconds_to_finish <= 0) {
            return ['ok' => false, 'message' => "tempo scaduto, non è più possibile inviare le risposte."];
        }
    }
    
    $answers = [];

    foreach($text->exercises as $exercise) {
        foreach ($exercise['questions'] as $question) {
            $form_id = $question['form_id'];
            $answer = array_get($_POST, $form_id);
            if ($answer === null) return ['ok' => false, 'message' => 'richiesta non valida'];
            array_push($answers, [
                'id' => $question['id'],
                'form_id' => $form_id,
                'answer' => $answer,
                ]);
            }
        }
    
    $timestamp = $exam->write($matricola, $user, 'submit', $answers);

    // ricomponi il testo con le nuove risposte
    $text = new Text($exam, $matricola, false);
    $response = $text->response_for($user);
    $response['timestamp'] = $timestamp;
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

function respond($action, $exam, $user) {
    my_log("POST ".$action." ".$exam->exam_id." ".array_get($user, 'matricola', 'user not authenticated'));
    // error_log("POST ".$action." ".$exam->exam_id." ".array_get($user, 'user', 'user not authenticated'));
    if ($user == null) {
        if ($action == 'login') throw new ResponseError("utente non riconosciuto o password non valida");
        else if ($action == 'load') {
            // if the user loads the page and there is no open session
            // there is no authentication and no error
            return [
                'ok' => True,
                'user' => null // require authentication
            ];
        }
        throw new ResponseError("utente non autenticato");
    }

    if ($action === 'logout') {
        session_destroy();   
        $user = null;
        return ['ok' => True];
    } else if ($action === 'reload' || $action === 'load' || $action == 'login') {
        $matricola = $user['matricola'];
        if ($user['is_admin']) {
            $matricola = array_get($_POST, 'matricola', '');
            $show_variants = array_get($_POST, 'variants') === 'true';
            $text = new Text($exam, $matricola, $show_variants);  
        } else {
            // non admins cannot inspect variations
            $text = new Text($exam, $matricola, false);
        }
        return $text->response_for($user);
    } else if ($action === 'start') {
        $matricola = $user['matricola'];
        $text = new Text($exam, $matricola, false);
        if (!$exam->is_open) throw new ResponseError("l'esame non è aperto");
        if ($exam->check_students && $text->student === null) throw new ResponseError("lo studente non è iscritto");
        if ($exam->start_timestamp && !$exam->can_be_repeated) {
            // l'esame era già stato avviato!
            throw new ResponseError("l'esame è già stato avviato");
        }
        $exam->write($matricola, $user, 'start', True); /* segnamo l'inizio del compito */
        // to recompute timers
        $text = new Text($exam, $matricola, false);
        return $text->response_for($user);
    } else if ($action === 'submit') {
        return submit($exam, $user);
    } else if ($action === 'pdf_upload') {
        if (! ($exam->upload_is_open || $user['is_admin'])) {
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
        $now = new DateTime('NOW');
        if ($user['is_admin']) {
            // mantieni il nome del file dato dall'amministratore
            $matricola = array_get($_POST, 'matricola');
            $filename = $file['name'];
            if (!$exam->pdf_filename_is_valid($filename, $matricola)) $filename = $matricola . '_' . $filename;
        } else {
            $matricola = $user["matricola"];
            $filename = $matricola . "_" . $now->format('c') . ".pdf";    
        }
        if (!$exam->pdf_filename_is_valid($filename, $matricola)) throw new ResponseError("il nome utilizzato per il file non è valido");
        $filename = $exam->storage_path . "/" . $filename;
        $r = move_uploaded_file($file["tmp_name"], $filename);
        if ($r) {
            $hash = md5_file($filename);
            my_log("upload " . $filename. " md5 hash " . $hash);
            $dir = $exam->get_files_list($matricola);
            return [
                'ok' => True,
                'dir' => $dir
                ];
        } else {
            my_log("upload " . $filename . " failed");
            throw new ResponseError("upload fallito");
        }
    } else if ($action === 'pdf_delete') {
        $matricola = null;
        if ($user['is_admin']) {
            $matricola = array_get($_POST, 'matricola');
        } else {
            if (!$exam->upload_is_open) throw new ResponseError("non è più possibile rimuovere files");
            $matricola = $user['matricola'];
        }
        $filename = array_get($_POST, 'filename');
        if ($exam->pdf_filename_is_valid($filename, $matricola)) {
            my_log("REMOVE FILE " . $filename);
            $filename = $exam->storage_path . "/" . $filename;
            unlink($filename);
            $dir = $exam->get_files_list($matricola);
            return [
                'ok' => True,
                'dir' => $dir
            ];
        } else {
            my_log("REMOVE FILE INVALID FILENAME " . $filename);
            throw new ResponseError("nome file non valido");
        }
    } else if ($action === 'pdf_download') {
        $matricola = $user['matricola'];
        if ($user['is_admin']) {
            $matricola = array_get($_POST, 'matricola', $matricola);
        }
        $filename = array_get($_POST, 'filename');
        if ($exam->pdf_filename_is_valid($filename, $matricola)) {
            $filename = $exam->storage_path . "/" . $filename;
            header("Content-type: application/pdf");
            // Send the file to the browser.
            readfile($filename);
            return null;
        } else {
            throw new ResponseError("non autorizzato");
        }
    } else if ($action === 'csv_download') {
        $with_log = (array_get($_POST, 'with_log') == '1');
        error_log("with_log: " . $with_log);
        error_log("POST: " . json_encode($_POST));
        if (!$user['is_admin']) throw new ResponseError("user not authorized");
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="'.$exam->exam_id.'.csv";');
        ob_clean(); // clear blank lines caused by PHP parsing file
        $fp = fopen('php://output', 'w');
        $exam->csv_response($fp, $with_log);
        fclose($fp);
        return null;
    } else if ($action === 'get_students') {
        return [
            'ok' => True,
            'students' => $exam->get_student_list()
        ];
    } else {
        error_log("richiesta non valida [$action]");
        if (empty($_FILES) && empty($_POST)) {
                // catch file overload error...
                $postMax = ini_get('post_max_size'); //grab the size limits...
                throw new ResponseError("richiesta non valida (il server limita la dimensione massima degli upload a: $postMax)");
        }
        throw new ResponseError("richiesta non valida");
    }
    // should never reach here
    throw new AssertionError("internal error #47895");
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

//$_SESSION['user'] = null; // test session timeout

$user = array_get($_SESSION, 'user', null);
if ($action == 'login') {
    $user = null;
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
} 

if ($user != null) {
    $user['is_admin'] = $exam->is_admin(array_get($user, 'matricola'));
}

?>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>

<?php
try {
    try {
        $response = respond($action, $exam, $user);
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

table {
    border-collapse: collapse;
    border: 1px solid orange;
}

table td {
    border-left: 1px solid dimgrey;
    border-right: 1px solid dimgrey;
    text-align: center;
}

table td:first-child {
    border-left: none;
}

table td:last-child {
    border-right: none;
}

table th {
    border-left: 1px solid dimgrey;
    border-right: 1px solid dimgrey;
    text-align: center;
}

table th:first-child {
    border-left: none;
}

table th:last-child {
    border-right: none;
}

.loader {
  border: 8px solid #f3f3f3; /* Light grey */
  border-top: 8px solid #00ff00; /* green */
  border-radius: 50%;
  width: 40px;
  height: 40px;
  animation: spin 2s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
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
            <b>Matricola:</b> <span id="matricola"></span> <button id="logout">logout</button><br />        
        </div>
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
        <div id="submit_div">       
            <div id="timer"></div>
            <button id="submit" hidden>invia risposte</button>
            <input type="checkbox" id="show_logs"><label for="show_logs">mostra modifiche alle risposte</label>
            <div id="response" style="color:blue"></div>
        </div>
        <div id="log">
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
            <div id="upload_message"></div>
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