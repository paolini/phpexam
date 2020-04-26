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

# sviluppo in locale

se non si ha ldap bisogna rimpiazzare authenticate() con fake_authenticate() nella 
seconda riga della funzione serve(...). 
Poi si può avviare un server in locale

    cd webroot
    php -S localhost:8000

si potrà accedere all'indirizzo 

    http://localhost:8000/?id=example

dove `example` si riferisce al file `example.xml` che descrive l'esame.

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

# creazione esami 

si guardi il file `example.xml`. Terminologia:

* *admins:* è un elenco di username separati da virgola. Questi utenti potranno vedere tutte le informazioni riservate 
sull'esame.

* *storage_path:* directory in cui vengono memorizzati i dati

* *shuffle:* gli elementi inclusi verranno mescolati tra loro

* *variants:* viene scelto un solo elemento tra quelli inclusi

* *exercise:* descrive un singolo esercizio

* *question:* descrive una domanda

* *answer:* la risposta attesa.

# possibili sviluppi futuri

* domande a risposta multipla
* correzione automatica
* compatibilità con il formato moodle_xml
* intervallo di apertura / chiusura dell'esame
* tempo massimo di svolgimento
