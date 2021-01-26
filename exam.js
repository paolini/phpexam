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
    form.target = "_blank"; // open in new tab

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

/**
 * ask user to login again and then execute the callback next()
 */
function reauthenticate(next) {
    error("sessione scaduta devi inserire le credenziali");
    $("#auth").show();
    pending = next;
}

function load(action) {
    var post = {};
    if (action == 'login') {
        post.user = $("#user").val();
        post.password = $("#password").val();
    }
    post.action = action;
    var matricola = $("#set_matricola").val();
    if (matricola != '') {
        post.variants = false;
        post.matricola = matricola;
    } else {
        post.variants = true;
    }
    clean_error();
    return $.post("", post, function(data, status) {
        if (data.user == undefined) data.user = null; // normalize
        if (data.ok) { 
            if (action == 'login' && pending != null) {
                // execute pending action that failed because of session timeout
                var f = pending;
                pending = null;
                return f();
            }
            if (action == 'logout') {
                error("La sessione è stata chiusa");
            }
        } else { // not data.ok
            if (data.user == null) {
                // invalid user: maybe the session is terminated
                // try to authenticate again and then
                // retry the same action
                reauthenticate(function() {
                    load(action);
                });
            } else {
                error(data.error || "errore interno 243");
            }
        }
        if (action == 'keepalive') return; // do nothing
        return main(data);
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
    post.action = "submit";

    data.text.exercises.forEach(function(exercise) {
        exercise.questions.forEach(function(question) {
            var val = $("#question_" + question.form_id).val();
            post[question.form_id] = val;
        })
    });
    clean_error();
    return $.post("", post, function(response, status) {
        if (response.user != null) {
            $("#auth").hide();
        } else {
            reauthenticate(function() {
                submit(data)
            });
        }
        if (response.message) {
            msg = response.message;
            if (response.timestamp) {
                msg = timestamp_to_string(new Date(response.timestamp)) + ": " + msg;
            }
            $("#response").text(msg);
        }
        if (response.ok == true) {
            data.text.exercises.forEach(function(exercise) {
                exercise.questions.forEach(function(question) {
                    question.answer = $("#question_" + question.form_id).val();
                });
            });
            data.submissions = response.submissions;
            main_compose_answer_log(data);
        } else {
            $("#response").css("color", "red");
        }
        data.text.exercises.forEach(function(exercise) {
            exercise.questions.forEach(function(question) {
                $("#question_" + question.form_id)
                    .val(question.answer)
                    .keyup();
            })
        });
    });
}

function seconds_to_human_string(s) {
    if (s<0) {
        return "-" + seconds_to_human_string(-s);
    }
    var show_seconds = s < 60*5;
    var h = Math.floor(s / 3600);
    s -= h*3600;
    var m = Math.floor(s / 60);
    s -= m*60;
    var h_msg = h.toString() + (h==1 ? " ora" : " ore");
    var m_msg = m.toString() + (m==1 ? " minuto" : " minuti");
    if (!show_seconds) {
        if (h == 0) return m_msg;
        if (m == 0) return h_msg;
        return h_msg + " e " + m_msg;
    }
    var s_msg = s.toString() + (s==1 ? " secondo" : " secondi");
    if (h == 0) {
        if (m==0) return s_msg;
        if (s==0) return m_msg;
        return m_msg + " e " + s_msg;
    }
    return h_msg + ", " + m_msg + " e " + s_msg;
}

var timer = null; // global singleton timer... so we don't leak!

function stop_timer() {
    if (timer != null) window.clearInterval(timer);
    timer = null;
}

function main_compose_exam_info(data) {
    $("#cognome").text(data.cognome);
    $("#nome").text(data.nome);
    $("#matricola").text(data.matricola);
    if (data.instructions_html != null) {
        $("#instructions").html(data.instructions_html).show();
    } else {
        $("#instructions").hide();
    }
    $("#set_matricola").val(data.matricola);
}

function main_compose_admin(data) {
    $.post("", { action: 'get_students'}, 
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
                error(response.error);
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
        }
    );
}

function main_compose_answer_log(data) {
    const submissions = data.submissions;
    $log = $("#log");
    $log.empty();
    if (submissions.length == 0) {
        $log.append($("<p>Non ci sono risposte</p>\n"));
        return;
    }

    $log.append($("<p>Modifiche alle risposte:</p>\n"));
    var keys = [];
    // find all used keys
    for (var i=0; i<submissions.length; ++i) {
        const answers = submissions[i].answers;
        if (answers == null) continue; // era lo start
        for (var j=0; j<answers.length; ++j) {
            const key = answers[j].id;
            if (!keys.includes(key)) keys.push(key);
        }
    }
    var $table = $("<table></table>");
    var $tr = $("<tr></tr>");
    $tr.append($("<th>istante</th><th>minuti</th>"));
    for (var i=0; i<keys.length; ++i) {
        var $th = $("<th></th>");
        $th.text(keys[i].replace('_', ' '));
        $tr.append($th);
    }
    $table.append($tr);
    for (var i=0;i<submissions.length; ++i) {
        const log = submissions[i];
        $tr = $("<tr></tr>");
        var $td = $("<td></td>");
        $td.text(timestamp_to_string(new Date(log.timestamp*1000)));
        $tr.append($td);
        $td = $("<td></td>");
        $td.text(Math.round(log.seconds / 60));
        $tr.append($td);
        for (var j=0; j<keys.length; ++j) {
            $td = $("<td></td>");
            var answers = log.answers == null ? [] : log.answers;
            for (var k=0; k < answers.length; ++k) {
                if (answers[k].id == keys[j]) {
                    $td.text(answers[k].answer);
                    break;
                }
            }
            $tr.append($td);
        }
        $table.append($tr);
    }
    $log.append($table);
}

function main_compose_text_timer(data) {
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
                    $("input.exam").attr('readonly', 'readonly');
                    stop_timer();
                }
            }, 1000);
        } else {
            $("#submit").hide();
            $("#timer").html("<span style='color:red'>non è possibile inviare le risposte</span>");
            if (data.can_be_repeated) {
                $restart_button = $("<button>ricomincia</button>");
                $restart_button.click(function() {load('start');});
                $("#timer").append("&nbsp;");
                $("#timer").append($restart_button);
            } else {
            }
            $("input.exam").attr('readonly', 'readonly');
        }
    }
}

function main_compose_text(data) {
    $("#upload").show();
    $("#legenda").show();
    var $exercises = $("#exercises");
    $exercises.empty();
    $exercises.append("<br>\n");
    if (!data.answers) data.answers = {};
    data.text.exercises.forEach(function(exercise, i) {
        $exercises.append("<b>Esercizio " + (exercise.number) + ":</b> " + exercise.statement + "<br />");
        exercise.questions.forEach(function(question) {
            var answer = question.answer || "";
            var $input = $("<input>")
                .attr("id", 'question_' + question.form_id)
                .attr("class", 'exam')
                .val(answer);
            $input.css("width","95%");
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
                    .append($("<span class='solution'></span>").css('color','red').html(question.solution))
                    .append($("<br class='solution'/>"));
            }
            $input.keyup(function() {
                var val = $(this).val();
                var submitted = question.answer || "";
                $check.css('color', val != submitted ?'red':(val=='' ? 'black' : 'green'));
                // if (changed) $("#response").empty();            
            }).keyup();
            $input.change(function() {
                submit(data);
            })
        });
        $exercises.append("<br /><br />");
    });
    MathJax.Hub.Queue(["Typeset",MathJax.Hub]);

    $("#submit").show().off('click').click(function(){submit(data)});
    if (data.matricola != data.user.matricola) {
        $("#submit").hide(); // evita di inviare dati di un utente impersonificato
        $("input.exam").attr('readonly', 'readonly');
    }
    $("#set_matricola").val(data.matricola);

    main_compose_text_timer(data);
}

function main_compose_no_text(data) {
    $("#legenda").hide();
    $("#upload").hide();
    var $exercises = $("#exercises");
    $exercises.empty();
    $exercises.append("<br>\n");

    function display_start_button() {
        var html = 
        $exercises.html("<span style='color:blue'><b>Quando sei pronto puoi <button id='start_button'>iniziare!</button></b></span>");
        $("#start_button").click(function(){load("start");});
        if (data.duration_minutes) {
            $exercises.append("<p>Durata della prova " + seconds_to_human_string(data.duration_minutes*60) + ".</p>");
        }
        if (data.end_time) {
            $exercises.append("<p>Da completare comunque entro le ore " + data.end_time + ".</p>");
            if (data.seconds_to_start_timeline > 0) {
                var target_time = Date.now() + 1000*data.seconds_to_start_timeline;
                stop_timer();
                var $timer = $("<span style='color:blue'></span>");
                $exercises.append($timer);
                timer = window.setInterval(function() {
                    var s = Math.round((target_time - Date.now()) / 1000);
                    if (s<0) s = 0;
                    $timer.html("<b>Devi iniziare il compito entro " + seconds_to_human_string(s) + "</b>");
                }, 1000);
                window.setTimeout(function() {
                    stop_timer();
                    $timer.html("<b>Il compito è iniziato!</b>");
                }, 1000*(data.seconds_to_start_timeline+1));
            } else {
                $exercises.append("<span style='color:red'><b>Il compito è già iniziato!</b></span>");
            }
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

function main(data) {
    stop_timer();

    if (data.user == undefined || data.user == null) {
        $("#auth").show();
        $("#admin").hide();
        $("#text").hide();
        return;
    }
    
    if (!data.ok) return; // don't change anything on the page!

    $("#auth").hide();
    
    main_compose_exam_info(data);

    if (data.user.is_admin) {
        $("#admin").show();
        main_compose_admin(data);
    } else {
        $("#admin").hide();
    }

    $("#text").show();
    if (data.text) { 
        // abbiamo il testo del compito!
        main_compose_answer_log(data);
        main_compose_text(data);
        $("#submit_div").show();
    } else {
        // non abbiamo il testo del compito
        $("#submit_div").hide();
        main_compose_no_text(data);
    }

    populate_pdf_list(data.file_list, data.upload_is_open || data.user.is_admin);
    
    $("#upload_message").empty();

    if (!data.upload_is_open) {
        if (data.user.is_admin) {
            upload_write_error("Il caricamento dei files è disabilitato per gli studenti.");
        } else {
            upload_write_error("Non è più possibile caricare i files.");
        }
    }

    if (data.upload_is_open || data.user.is_admin) {
        $(".upload").show();
    } else {
        $(".upload").hide();
    }
}

// upload scans

function readAsDataURL(file) {
    return new Promise((resolve, reject)=>{
      let fileReader = new FileReader();
      fileReader.onload = function(){
        return resolve({data:fileReader.result, name:file.name, size: file.size, type: file.type});
      }
      fileReader.readAsDataURL(file);
    })
  } 
  
  pages = [];
  
  async function draw_image(page) {
    var img = page.img;
    var rotation = page.rotation;
    var canvas = page.canvas;
  
    var MAX_RES = 800;
    var scale = MAX_RES / Math.min(img.width, img.height);
    if (scale > 1.0) scale = 1.0;
    var width = img.width * scale;
    var height = img.height * scale;
    if (rotation == 90 || rotation == 270) {
      canvas.width = height;
      canvas.height = width;
    } else {
      canvas.width = width;
      canvas.height = height;
    }
    var ctx = canvas.getContext("2d");
    ctx.translate(canvas.width/2,canvas.height/2);
    ctx.rotate(rotation*Math.PI/180);
    ctx.drawImage(img, -width/2, -height/2, width, height);
  }
  
  async function create_page(file) {
    var page = {};
    page.div = document.createElement("div");
    page.img = document.createElement("img");
    var load = await readAsDataURL(file);
    page.img.src = load.data;
    await page.img.decode();
    
    page.rotation = 0;
  
    page.canvas = document.createElement('canvas');
  
    var button_left = document.createElement('button');
    var button_right = document.createElement('button');
    var button_remove = document.createElement('button');
    button_left.innerText = "ruota a sinistra";
    button_right.innerText = "ruota a destra";
    button_remove.innerText = "rimuovi pagina";
    button_left.onclick = function() {
      page.rotation = (page.rotation + 270) % 360;
      draw_image(page);
    };
    button_right.onclick = function() {
      page.rotation = (page.rotation + 90) % 360;
      draw_image(page);
    };
    button_remove.onclick = function() {
        page.div.parentNode.removeChild(page.div);
        page.div = null;
        page.canvas = null;
    };
    page.div.appendChild(button_left);
    page.div.appendChild(button_right);
    page.div.appendChild(button_remove);
    page.div.appendChild(document.createElement('br'));
    page.div.appendChild(page.canvas);
    page.div.appendChild(document.createElement('br'));
    page.div.appendChild(document.createElement('hr'));
  
    draw_image(page);
    // var dataurl = page.canvas.toDataURL("image/png");
  
    return page;
  }

  function upload_write_error(msg) {
    $p = $("<p></p>").text(msg).css("color", "red");
    $("#upload_message").append($p);
  }

  function create_pdf() {
    $("#upload_pdf_id").hide();
    $("#upload_input_id").hide();
    if (pages.length==0) return;
    var doc = new jspdf.jsPDF({
      orientation: "p",
      unit: "mm",
      format: "a4",
      putOnlyUsedFonts: true
    });
    var page_count = 0;
    for (var n=0; n<pages.length; ++n) {
      var page = pages[n];
      if (page.canvas == null) continue; // pagina rimossa
      if (page_count>0) doc.addPage();
      const PAGE_WIDTH = 195;
      const PAGE_HEIGHT = 265;
      scale = PAGE_WIDTH / page.canvas.width;
      if (scale > 1.0) scale = 1.0;
      if (page.canvas.height * scale > PAGE_HEIGHT) {
        scale = PAGE_HEIGHT / page.canvas.height;
      }
      doc.addImage(page.canvas.toDataURL("image/jpeg"), 'JPEG', 
        5, 15, page.canvas.width*scale, page.canvas.height*scale);
      doc.text(10,10, $("#matricola").text() + " " + $("#cognome").text() + " " + $("#nome").text() + " pagina " + (page_count + 1));
      page_count ++;
    }
    var blob = new Blob([doc.output('blob')], { type: 'application/pdf'});
    post_pdf(blob);
    // var pdf = doc.output();
    // doc.save("out.pdf");
  };

  function post_pdf(blob) {
    formdata = new FormData();
    formdata.append('file', blob, blob.name);
    formdata.append('action', 'pdf_upload');
    formdata.append('matricola', $("#set_matricola").val()); // ignored if not admin
    $("#upload_input_id").hide();
    $("#upload_message").empty().append($("<div></div>").addClass("loader"));
    $.ajax({
        url: "",
        type: "POST",
        data: formdata,
        processData: false,
        contentType: false
    }).done(function(data){
        $("#upload_message").empty();
        if (data.ok) {
            pages = [];
            $("#upload_div_id").empty();
            populate_pdf_list(data.dir, true);
        } else {
            upload_write_error("Errore: " + data.error + ". File non caricato");
        } 
        $("#upload_input_id").show();
    });
  }
  
  async function upload(input, div) {
    for (var n=0; n <input.files.length; ++n) {
      var file = input.files[n];
      if (file.type == 'application/pdf' || file.name.toLowerCase().endsWith(".pdf")) {
        post_pdf(file);
      } else {
          // assume image
        try {
            var page = await create_page(file);
            div.append(page.div);
            pages.push(page);
        } catch(e) {
            if (e.name == 'EncodingError') {
                var msg = "Formato non valido: l'immagine non può essere decodificata";
            } else {
                msg = e.message;
            }
            upload_write_error(msg);
        }
      }
    }
    $('#upload_input_id').val('');
    if (pages.length>0) {
        $('#upload_pdf_id').show();
    }
  }
  
  function populate_pdf_list(files, show_delete_button) {
    $div = $("#upload_list");
    $div.empty();
    if (files.length == 0) {
        $div.append("<span style='color:red'>nessun file caricato</span>");
    }
    for(var i=0; i<files.length; ++i) {
        var li = document.createElement('li');
        var a = document.createElement('a');
        a.appendChild(document.createTextNode(files[i]));
        a.data_filename = files[i];
        a.href="#";
        a.onclick = function() {
            var data = {
                action: 'pdf_download',
                filename: this.data_filename,
                matricola: $("#set_matricola").val()
            };
            post("", data, "post");
        };
        li.appendChild(a);
        if (show_delete_button) {
            li.appendChild(document.createTextNode(" "));
            var button = document.createElement('button');
            button.appendChild(document.createTextNode("elimina"));
            button.data_filename = files[i];
            button.onclick = function() {
                if (confirm(this.data_filename+"\nVeramente vuoi eliminare il file?")) {
                    $.post("", {
                        action: 'pdf_delete',
                        matricola: $("#set_matricola").val(), // ignorato se non admin
                        filename: this.data_filename
                    }).done(function(data) {
                        if (data.ok) {
                            pages = [];
                            $("#upload_div_id").empty();
                            populate_pdf_list(data.dir, true);            
                        } else {
                            upload_write_error("Errore: " + data.error);
                        }
                    });
                }
            }
            li.appendChild(button);
        }
        $div[0].appendChild(li);
    }
  }  

// MAIN
var pending = null; // azione rimasta in sospeso da svolgere dopo il login

$(function(){
    $("#login").click(function() {
        load('login');
    });
    $("#logout").click(function() {
        load('logout')
    });
    $("#set_matricola").change(function(){
        load('reload')
    });
    $("#show_solutions").change(function(){
        $(".solution").toggle($("#show_solutions").is(":checked"));
    });
    $("#show_variants").change(function(){
        load('reload')
    });
    $("#csv_download").click(function() {
        post("", { action: 'csv_download',
                   with_log: $("#show_logs").is(":checked") ? '1' : '0'}, 'POST');
    });
    $("#upload_input_id").change(function() {
        $("#upload_pdf_id").hide();
        $("#upload_message").empty();
        upload(this, $("#upload_div_id")[0]);
    });
    $("#upload_pdf_id").click(create_pdf).hide();
    $("#show_logs").change(function(){
        $('#log').toggle($('#show_logs').is(":checked"));
    }).change();

    load('load');
    window.setInterval(function() {
        load('keepalive');
    }, 5*60*1000); // every 5 minutes
})