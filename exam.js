function error(error_message) {
    $("#error").text(error_message).show();
}

function clean_error() {
    $("#error").text("").hide();
}

function load(action) {
    var post = {};
    post.user = $("#user").val();
    post.password = $("#password").val();
    post.action = action;
    if (action == 'reload') {
        post.matricola = $("#set_matricola").val();
        post.solutions = $("#show_solutions").prop('checked');
        post.variants = $("#show_variants").prop('checked');
        $("#set_matricola").prop('disabled', post.variants);
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
        if (action == 'reload') main(data);
        else login(data);
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

    data.text.exercises.forEach(function(exercise) {
        exercise.questions.forEach(function(question) {
            post['answer_' + question.form_id] = $("#question_" + question.form_id).val();
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
            $("#response").css("color", "blue");
            data.text.exercises.forEach(function(exercise) {
                exercise.questions.forEach(function(question) {
                    if ($("#question_" + question.form_id).val() == "") {
                        $("#check_" + question.form_id).css("color", "black");
                    } else {
                        $("#check_" + question.form_id).css("color", "green");
                    }
                })
            })
        } else {
            $("#response").css("color", "red");
        }
    });
}

function login(data) {
    $("#cognome").text(data.user.cognome);
    $("#nome").text(data.user.nome);
    $("#matricola").text(data.user.matricola);
    $("#set_matricola").val(data.user.matricola);
    if (data.is_admin) $("#admin").show();
    load("reload");
}

function seconds_to_human_string(s) {
    var msg = "";
    if (s<0) {
        msg += "-";
        s = -s;
    }
    var h = Math.floor(s / 3600);
    s -= h*3600;
    var m = Math.floor(s / 60);
    s -= m*60;
    if (h>0) msg += h.toString() + (h==1 ? " ora, " : " ore, ");
    if (h>0 || m>0) msg += m.toString() + (m==1 ? " minuto e " : " minuti e ");
    msg += s.toString() + (s==1 ? " secondo" : " secondi");
    return msg;
}

var timer = null; // global singleton timer... don't leak!

function stop_timer() {
    if (timer != null) window.clearInterval(timer);
    timer = null;
}

function main(data) {
    stop_timer();
    $("#text").show();
    var $exercises = $("#exercises");
    $exercises.empty();
    $exercises.append("<br>\n");
    if (data.text) { // abbiamo il testo del compito!
        data.text.exercises.forEach(function(exercise, i) {
            $exercises.append("<b>Esercizio " + (exercise.number) + ":</b> " + exercise.statement + "<br />");
            exercise.questions.forEach(function(question) {
                $exercises.append(""
                + "<span class='check' id='check_" + question.form_id + "'>&#9632;</span> "
                + "<i>" + question.statement + "</i> "
                + "<input id='question_" + question.form_id + "'>"
                + "<br />");
                if (question.solution) {
                    $exercises.append("<span style='color:red'>" + question.solution + "</span><br />\n");
                }
                $("#question_" + question.form_id).change(function() {
                    $("#check_" + question.form_id).css("color", "red");
                    $("#response").empty();                
                })
            });
            $exercises.append("<br /><br />");
        });
        MathJax.Hub.Queue(["Typeset",MathJax.Hub]);
        $("#submit").show().click(function(){submit(data)});
        $("#set_matricola").change(function(){load('reload')});
        $("#show_solutions").change(function(){load('reload')});
        $("#show_variants").change(function(){load('reload')});

        $("#timer").empty();
        if (data.seconds_to_finish !== null) {
            if (data.seconds_to_finish >= 0) {
                var target_time = Date.now() + 1000*data.seconds_to_finish;
                timer = window.setInterval(function() {
                    var s = Math.round((target_time - Date.now())/1000);
                    if (s<0) s = 0;
                    var color = "";
                    if (s < 60) color = "red";
                    else if (s< 5*60) color = "orange";
                    else color = "blue";
                    $("#timer").html("<span style='color:" + color + "'>Tempo rimanente: " + seconds_to_human_string(s) + "</span>");
                }, 1000);
            }
        }
    } else {
        function display_start_button() {
            $exercises.html("<span style='color:blue'><b>Quando sei pronto puoi <button id='start_button'>iniziare!</button></b></span>");
            $("#start_button").click(function(){load("reload");});
        }
        // il testo non e' disponibile
        if (data.is_open) {
            display_start_button();
        } else {
            if (data.seconds_to_start > 0) {
                var timer = null;
                var target_time = Date.now() + 1000*data.seconds_to_start;
                timer = window.setInterval(function() {
                    var s = Math.round((target_time - Date.now()) / 1000);
                    if (s<0) s = 0;
                    $exercises.html("<span style='color:red'><b>Potrai iniziare il compito tra " + seconds_to_human_string(s) + "</b></span>");
                }, 1000);
                window.setTimeout(function() {
                    window.clearInterval(timer);
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
})