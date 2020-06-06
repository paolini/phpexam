function error(error_message) {
    $("#error").text(error_message).show();
}

function clean_error() {
    $("#error").text("").hide();
}

/**
 * sends a request to the specified url from a form. this will change the window location.
 * @param {string} path the path to send the post request to
 * @param {object} params the paramiters to add to the url
 * @param {string} [method=post] the method to use on the form
 */

function post(path, params, method='post') {

    // The rest of this code assumes you are not using a library.
    // It can be made less wordy if you use one.
    const form = document.createElement('form');
    form.method = method;
    form.action = path;
  
    for (const key in params) {
      if (params.hasOwnProperty(key)) {
        const hiddenField = document.createElement('input');
        hiddenField.type = 'hidden';
        hiddenField.name = key;
        hiddenField.value = params[key];
  
        form.appendChild(hiddenField);
      }
    }
  
    document.body.appendChild(form);
    form.submit();
  }

function load(action) {
    var post = {};
    post.user = $("#user").val();
    post.password = $("#password").val();
    post.action = action;
    post.solutions = $("#show_solutions").prop('checked');
    var matricola = $("#set_matricola").val();
    if (matricola != '') {
        post.variants = false;
        post.matricola = matricola;
    } else {
        post.variants = true;
    }
    clean_error();
    $.post("", post, function(data, status) {
        if (!data.ok) {
            error(data.error || "errore interno 243");
            return;
        }
        if (!data.user.authenticated) {
            error("errore interno 231");
            return;
        }
        $("#auth_error").text("").hide();
        $("#auth").hide();
        main(data);
    });
}

function timestamp_to_string(d) {
    function digits(n) {
        if (n<10) return "0" + n.toString();
        return n.toString();
    }
    return d.getDate() + "." + (d.getMonth()+1) + "." + d.getFullYear() 
        + " " + d.getHours() + ":" + digits(d.getMinutes()) + ":" + digits(d.getSeconds());
}

function submit(data) {
    var post = {};
    post.user = $("#user").val();
    post.password = $("#password").val();
    post.action = "submit";

    var new_answers = {};
    data.text.exercises.forEach(function(exercise) {
        exercise.questions.forEach(function(question) {
            var val = $("#question_" + question.form_id).val();
            post['answer_' + question.form_id] = val;
            new_answers[question.form_id] = val;
        })
    });
    
    $.post("", post, function(response, status) {
        if (response.user.authenticated) {
            $("#auth_error").text("").hide();
            $("#auth").hide();
        } else {
            $("#auth_error").text(response.user.error || "errore interno #231").show();
            $("#auth_error").show();
            $("#auth").show();
        }
        if (response.message) {
            msg = response.message;
            if (response.timestamp) {
                msg = timestamp_to_string(new Date(response.timestamp)) + ": " + msg;
            }
            $("#response").text(msg);
        }
        if (response.ok == true) {
            data.answers = new_answers;
            Object.entries(new_answers).forEach(function(item){
                var key = item[0];
                var val = item[1];
                data.answers[key] = val;
                $("#question_" + key).change();
            });
        } else {
            $("#response").css("color", "red");
        }
    });
}

function seconds_to_human_string(s) {
    var msg = "";
    if (s<0) {
        msg += "-";
        s = -s;
    }
    var show_seconds = s < 60*5;
    var h = Math.floor(s / 3600);
    s -= h*3600;
    var m = Math.floor(s / 60);
    s -= m*60;
    if (h>0) msg += h.toString() + (h==1 ? " ora" : " ore") + (show_seconds?", ":" e ");
    if (h>0 || m>0) msg += m.toString() + (m==1 ? " minuto" : " minuti");
    if (show_seconds) {
        if (m>0) msg += " e ";
        msg += s.toString() + (s==1 ? " secondo" : " secondi");
    }
    return msg;
}

var timer = null; // global singleton timer... so we don't leak!

function stop_timer() {
    if (timer != null) window.clearInterval(timer);
    timer = null;
}

function main(data) {
    stop_timer();

    $("#cognome").text(data.cognome);
    $("#nome").text(data.nome);
    $("#matricola").text(data.matricola);
    if (data.instructions_html != null) {
        $("#instructions").html(data.instructions_html).show();
    } else {
        $("#instructions").hide();
    }
    $("#set_matricola").val(data.matricola);
    if (data.user.is_admin) {
        $("#admin").show();
        $.post("", {
                user: $("#user").val(),
                password: $("#password").val(),
                action: 'get_students'}, 
                function(response, status) {
                    $select = $('#select_student');
                    var count = 0;
                    if (response.ok) {
                        $select.empty();
                        $select.append($("<option></option>").val("").text('-- mostra tutte le varianti --'));
                        response.students.forEach(function(student) {
                            var $option = $("<option></option>").val(student.matricola).text(student.cognome + ' ' + student.nome);
                            if (student.matricola == data.matricola) $option.attr("selected","selected");
                            $select.append($option);
                            count ++;
                        });
                    } else {
                        console.log(response.error);
                    }
                    if (count > 0) {
                        $select.show();
                        $select.off("change");
                        $select.change(function() {
                            var val = $select.val();
                            $('#set_matricola').val(val).change();
                        });
                    } else {
                        $select.hide();
                    }            
                });
    }

    $("#text").show();
    var $exercises = $("#exercises");
    $exercises.empty();
    $exercises.append("<br>\n");
    if (data.text) { // abbiamo il testo del compito!
        if (!data.answers) data.answers = {};
        data.text.exercises.forEach(function(exercise, i) {
            $exercises.append("<b>Esercizio " + (exercise.number) + ":</b> " + exercise.statement + "<br />");
            exercise.questions.forEach(function(question) {
                var answer = "";
                if (data.answers.hasOwnProperty(question.form_id)) {
                    answer = data.answers[question.form_id];
                }
                var $input = $("<input>").attr("id", 'question_' + question.form_id).val(answer);
                $input.css("width","100%");
                var $check = $("<span>").addClass('check')
                    .attr('id', 'check_' + question.form_id)
                    .html("&#9632");
                $exercises.append(
                    $("<p></p>").addClass('left')
                        .append($check)
                        .append($("<i></i>").html(question.statement))
                        .append($("<span></span>").addClass("fill").append($input))
                    );
                if (question.solution) {
                    $exercises
                        .append($("<span></span>").css('color','red').html(question.solution))
                        .append($("<br />"));
                }
                $input.change(function() {
                    var val = $(this).val();
                    var changed = (val != data.answers[question.form_id]);
                    $check.css("color", val==""?"black":changed?"red":"green");
                    if (changed) $("#response").empty();                
                }).change();
            });
            $exercises.append("<br /><br />");
        });
        MathJax.Hub.Queue(["Typeset",MathJax.Hub]);
        $("#submit").show().off('click').click(function(){submit(data)});
        if (data.matricola != data.user.matricola) $("#submit").hide(); // evita di inviare dati di un utente impersonificato
        $("#set_matricola").val(data.matricola);

        $("#timer").empty();
        if (data.seconds_to_finish !== null) {
            if (data.seconds_to_finish > 0) {
                var target_time = Date.now() + 1000*data.seconds_to_finish;
                stop_timer();
                timer = window.setInterval(function() {
                    var s = Math.round((target_time - Date.now())/1000);
                    if (s<0) s = 0;
                    var color = "";
                    if (s < 60) color = "red";
                    else if (s< 5*60) color = "orange";
                    else color = "blue";
                    $("#timer").html("<span style='color:" + color + "'>Tempo rimanente: " + seconds_to_human_string(s) + "</span>");
                    if (s <= 0) {
                        $("#timer").html("<span style='color:" + color + "'>Tempo scaduto</span>");
                        $("#submit").hide();
                        stop_timer();
                    }
                }, 1000);
            } else {
                $("#submit").hide();
            }
        }
    } else {
        // non abbiamo il testo del compito
        function display_start_button() {
            $exercises.html("<span style='color:blue'><b>Quando sei pronto puoi <button id='start_button'>iniziare!</button></b></span>");
            $("#start_button").click(function(){load("start");});
            if (data.duration_minutes) {
                $exercises.append("<p>Durata della prova " + seconds_to_human_string(data.duration_minutes*60) + ".</p>");
            }
            if (data.end_time) {
                $exercises.append("<!-- p>Da completare comunque entro le ore " + data.end_time + ".</p-->");
            }
        }
        if (data.is_open) {
            display_start_button();
        } else {
            if (data.seconds_to_start > 0) {
                var target_time = Date.now() + 1000*data.seconds_to_start;
                stop_timer();
                timer = window.setInterval(function() {
                    var s = Math.round((target_time - Date.now()) / 1000);
                    if (s<0) s = 0;
                    $exercises.html("<span style='color:red'><b>Potrai iniziare il compito tra " + seconds_to_human_string(s) + "</b></span>");
                }, 1000);
                window.setTimeout(function() {
                    stop_timer();
                    display_start_button();
                }, 1000*(data.seconds_to_start+1));
            } else {
                $exercises.append("<span style='color:red'><b>Non è possibile svolgere il compito in questo momento.</b></span>");
            }
        }
    }
}

$(function(){
    $("#login").click(function() {load('login');});
    $("#set_matricola").change(function(){load('reload')});
    $("#show_solutions").change(function(){load('reload')});
    $("#show_variants").change(function(){load('reload')});
    $("#csv_download").click(function() {
        post("", {
            user: $("#user").val(),
            password: $("#password").val(),
            action: 'csv_download'            
        },"POST");
    });
})