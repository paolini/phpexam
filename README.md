# descrizione

L'applicazione phpexam serve per presentare online un esame a risposte aperte.
E' possibile descrivere diverse varianti di ogni domanda/risposta. Il sistema autentica 
gli studenti mediante ldap. In base al numero di matricola viene generato un compito 
casuale tra quelli predisposti. Lo studente può compilare le risposte (aperte) e può sottometterle 
in qualunque momento. Le risposte vengono memorizzate sul server, un file per ogni studente.
Ogni risposta ha associato un timestamp e vengono riportati gli identificatori della domanda 
e la risposta prevista (per poter valutare il compito). Guardando l'andamento dei timstamp 
è possibile capire i tempi con cui lo studente ha risposto alle diverse domande.

I docenti potranno vedere il compito di ogni studente (inserendo il numero di matricola)
inoltre potranno vedere tutte le varianti e le soluzioni.

Il file di descrizione di un esame è un file XML. Il sistema è integrato con MathJax e dunque 
è possibile scrivere formule matematiche nei testi dei problemi.

# installazione

Eseguire il seguente comando se si vuole utilizzare mustache. Dovrebbe creare la directory `vendor':

    composer install

# sviluppo in locale

Si può avviare un server in locale

    cd webroot
    php -S localhost:8000

si potrà accedere all'indirizzo 

    http://localhost:8000/?id=example

dove `example` si riferisce al file `example.xml` che descrive l'esame.
Se non si ha ldap bisogna aggiungere 'fake' ai metodi di autenticazione

# messa in produzione

copiare il codice su un server nella propria home directory 
(non nelle cartelle pubbliche servite da apache altrimenti il file con 
le domande e le risposte risulterebbe accessibile da tutti).

Nelle cartelle public_html di apache mettere un link simbolico alla direcotry
"webroot". Ad esempio:

    public_html/exam -> /home/paolini/phpexam/webroot/

Il servizio sarà accessibile all'indirizzo:

    https://pagine.dm.unipi.it/paolini/exam?id=example

(effettivamente questo è un indirizzo valido, lo puoi provare).

Al primo utilizzo il server crea la cartella per memorizzare i dati forniti 
dagli utenti. Bisognerà assicurarsi che l'utente utilizzato da apache (spesso www_data)
abbia momentaneamente l'accesso in scrittura alla directory in cui vengono memorizzati i dati.

# funzionalità

E' possibile descrivere gli esercizi da somministrare tramite un file xml. Nel file vanno elencati 
tutti gli esercizi e le domande. Di ogni esercizio è possibile scrivere delle varianti. Gli esercizi poi
possono essere mescolati (anche a gruppi). Si può scriver in LaTeX.

Si può indicare la data e l'ora di apertura e chiusura del compito. Agli studenti viene indicato il tempo 
che manca all'apertura del compito (se non ancora iniziato) o al termine (se già iniziato). Si può indicare una 
durata massima che viene calcolata dal momento di inizio effettivo per ogni singolo studente. Lo studente 
può inviare le risposte durante tutto lo svolgimento, vengono memorizzate tutte con un timestamp. Se ricarica la pagina 
lo studente vede le ultime risposte inviate (anche dopo il termine della prova). Dopo il termine della prova si può 
decidere di mostrare le soluzioni del proprio compito.

Gli utenti amministratori possono vedere tutte le varianti degli esercizi e le soluzioni. Possono anche visualizzare il testo
e le risposte inserite da ogni singolo studente. Possono infine scaricare i dati in formato CSV.

# creazione esami 

si guardi il file `example.xml`. Terminologia:

* *admins:* è un elenco di username separati da virgola. Questi utenti potranno vedere tutte le informazioni riservate 
sull'esame.

* *storage_path:* directory in cui vengono memorizzati i dati

* *course:* nome del corso

* *name:* nome della prova 

* *date:* data nel formato "gg.mm.aaaa". Il valore "everyday" assegna 
la data in cui si accede alla pagina. Default: null.

* *time:* ora di inizio nel formato hh:mm. Non viene considerato se non 
si specifica anche "date".

* *duration_minutes:* durata della prova

* *end_time:* ora in cui la prova viene chiusa

* *end_upload_time:* ora in cui viene chiuso il caricamento dei files

* *can_be_repeated:* permette allo studente di riavviare l'esame a piacere (utile per gli esami di prova)

* *secret:* chiave utilizzata per generare le varianti

* *admins:* elenco delle matricole degli amministratori, separate da virgola

* *storage_path:* directory in cui vengono creati i files con i dati raccolti

* *auth_methods:* metodi di autenticazione tentati. Attualmente disponibili: ldap, fake.
L'autenticazione ldap è attualmente quella dell'ateneo unipi. L'autenticazione fake 
va usata solo per lo sviluppo in quanto accetta qualunque username e password.

* *publish_solutions:* mostra immediatamente a tutti le soluzioni. Default: false

* *publish_text:* mostra immediatamente a tutti il testo del compito. Default: false

* *show_instructions:* mostra le istruzioni. Default: true

* *show_legenda:* mostra la legenda. Default: true

* *students_csv:* nome di un file csv contenente una riga di intestazione e poi una riga per ogni studente. Ci deve essere una colonna con intestazione "matricola". Nelle \<instructions> se `mustache` è abilitato sarà allora possibile fare riferimento ai campi relativi allo studente. Se ad esempio si inserisce una colonna "nome" si potrà ottenere il valore di quel campo con la sintassi {{# student }}{{ nome }}{{/ student }}

* *csv_delimiter:* carattere utilizzato per la separazione dei campi 
nel file csv.

* *check_students:* se abilitato solo gli studenti presenti nel file csv possono svolgere il compito. Default: false

* *\<exam>:* la radice del file xml

* *\<instructions>:* testo da presentare su ogni compito. Mettere l'attributo `format=html`
se è scritto in html. Mettere l'attributo `engine=mustache` per usare il template engine omonimo.
Se è stato specificato un file csv con l'elenco degli studenti è possibile interpolare il testo 
con i valori relativi allo studente. Ad esempio:

``` 
<instructions format=html engine=mustache>
{{# student }}
Cognome: {{ cognome }}, Nome: {{ nome }}
{{/ student }}
</instructions>
```

* *\<shuffle>:* gli elementi inclusi verranno mescolati tra loro

* *\<variants>:* viene scelto un solo elemento tra quelli inclusi

* *\<exercise>:* descrive un singolo esercizio

* *\<question>:* descrive una domanda

* *\<answer>:* la risposta attesa.

* ogni altro tag che finisce con un *underscore* (ad esempio \<ignore_>)
verrà ignorato.

# possibili sviluppi futuri

* domande a risposta multipla

* correzione automatica

* compatibilità con il formato moodle_xml

