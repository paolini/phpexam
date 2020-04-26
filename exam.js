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
        if (data.user.is_fake) {
            $("#user_div").css("background","red");
        }
        if (action == 'reload') main(data);
        else login(data);
    });
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
    
    $.post("", post, function(data, status) {
        if (data.user.authenticated) {
            $("#auth_error").text("").hide();
            $("#auth").hide();
        } else {
            $("#auth_error").text(data.user.error || "errore interno #231").show();
            $("#auth_error").show();
            $("#auth").show();
        }
        if (data.message) {
            $("#response").text(data.message);
        }
        if (data.ok == true) {
            $("#response").css("color", "blue");
            $("span.check").css("color", "green");
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

function main(data) {
    $("#text").show();
    var $exercises = $("#exercises");
    $exercises.empty();
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
}

$(function(){
    $("#login").click(function() {load('login');});
})