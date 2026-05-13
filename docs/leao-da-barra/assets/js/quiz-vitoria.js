/**
 * Quiz do Vitória - Game Engine
 * 
 * 6 níveis, 5 perguntas por nível, timer por pergunta,
 * Google Sign-In, anti-cheat, ranking, compartilhamento
 * 
 * @package LeaoDaBarra
 */

(function() {
    'use strict';

    const root = document.getElementById('ldb-quiz-app');
    if (!root) return;

    const CFG = window.ldbQuizConfig || {};
    const API = CFG.restUrl || '/wp-json/ldb/v1/quiz/';

    // ============================================================
    // QUESTIONS DATABASE - perguntas com IDs estáveis para anti-cheat
    // Cada nível tem mais perguntas que o necessário para escolher aleatoriamente
    // ============================================================
    const LEVELS = [
        {
            name: "Torcedor",
            color: "#D4A843",
            icon: "1",
            timePerQuestion: 20,
            perGame: 5,
            questions: [
                { id: "t01", q: "Em que ano o Esporte Clube Vitoria foi fundado?", opts: ["1899", "1901", "1910", "1895"], answer: 0 },
                { id: "t02", q: "Qual o mascote do Vitoria?", opts: ["Aguia", "Leao", "Tigre", "Falcao"], answer: 1 },
                { id: "t03", q: "Quais as cores do Vitoria?", opts: ["Azul e branco", "Verde e amarelo", "Vermelho e preto", "Branco e vermelho"], answer: 2 },
                { id: "t04", q: "Qual o nome do estadio do Vitoria?", opts: ["Arena Fonte Nova", "Barradao", "Pituacu", "Estadio de Salvador"], answer: 1 },
                { id: "t05", q: "Qual o apelido mais famoso do Vitoria?", opts: ["Rubro-Negro da Barra", "Leao da Barra", "Nego Rubro", "Colossal da Bahia"], answer: 1 },
                { id: "t06", q: "Em que estado fica o Vitoria?", opts: ["Pernambuco", "Ceara", "Bahia", "Sergipe"], answer: 2 },
                { id: "t07", q: "O Vitoria joga em que liga nacional?", opts: ["Copa do Nordeste apenas", "Campeonato Baiano apenas", "Brasileirao", "Copa Sul-Americana apenas"], answer: 2 },
                { id: "t08", q: "Quantos jogadores entram em campo pelo Vitoria?", opts: ["10", "11", "12", "9"], answer: 1 },
                { id: "t09", q: "Qual a capital onde fica o Vitoria?", opts: ["Feira de Santana", "Salvador", "Ilheus", "Vitoria da Conquista"], answer: 1 },
                { id: "t10", q: "O maior classico do Vitoria e contra qual time?", opts: ["Sport", "Ceara", "Bahia", "Nautico"], answer: 2 },
                { id: "t11", q: "Qual o dia da semana tipico dos jogos do Brasileirao?", opts: ["Segunda", "Quarta e domingo", "Terca", "So sexta"], answer: 1 },
                { id: "t12", q: "O uniforme principal do Vitoria tem qual padrao?", opts: ["Listras horizontais", "Listras verticais", "Xadrez", "Liso azul"], answer: 1 },
                { id: "t13", q: "O Barradao fica em qual cidade?", opts: ["Salvador", "Feira de Santana", "Camacari", "Lauro de Freitas"], answer: 0 },
                { id: "t14", q: "Qual o mes de aniversario do Vitoria?", opts: ["Maio", "Junho", "Julho", "Abril"], answer: 0 },
                { id: "t15", q: "Quantos anos o Vitoria fez em 2024?", opts: ["100", "115", "125", "130"], answer: 2 },
            ]
        },
        {
            name: "Fanático",
            color: "#2E86C1",
            icon: "2",
            timePerQuestion: 18,
            perGame: 5,
            questions: [
                { id: "f01", q: "Em que cidade o Vitoria foi fundado?", opts: ["Feira de Santana", "Ilheus", "Salvador", "Recife"], answer: 2 },
                { id: "f02", q: "Qual foi o primeiro nome do clube?", opts: ["Sport Club Victoria", "Club de Cricket Victoria", "Vitoria Futebol Clube", "Club Atletico Victoria"], answer: 1 },
                { id: "f03", q: "Quantos titulos da Copa do Nordeste o Vitoria possui?", opts: ["2", "3", "4", "5"], answer: 2 },
                { id: "f04", q: "Em que ano o Barradao foi inaugurado?", opts: ["1980", "1986", "1990", "1994"], answer: 1 },
                { id: "f05", q: "Qual o dia de aniversario do Vitoria?", opts: ["13 de maio", "7 de maio", "1 de maio", "20 de maio"], answer: 0 },
                { id: "f06", q: "Em que bairro o Vitoria foi fundado?", opts: ["Pelourinho", "Corredor da Vitoria", "Barra", "Rio Vermelho"], answer: 1 },
                { id: "f07", q: "Quantos titulos baianos o Vitoria tem?", opts: ["25", "28", "30", "35"], answer: 2 },
                { id: "f08", q: "Em 2023 o Vitoria foi campeao de qual competicao nacional?", opts: ["Copa do Brasil", "Serie A", "Serie B", "Copa do Nordeste"], answer: 2 },
                { id: "f09", q: "Qual a atual serie em que o Vitoria disputa?", opts: ["Serie D", "Serie C", "Serie B", "Serie A"], answer: 3 },
                { id: "f10", q: "Quantos brasileiros fundaram o Vitoria?", opts: ["10", "15", "19", "22"], answer: 2 },
                { id: "f11", q: "O Vitoria foi o primeiro clube do Brasil fundado por:", opts: ["Ingleses", "Portugueses", "Brasileiros", "Italianos"], answer: 2 },
                { id: "f12", q: "Qual esporte deu origem ao nome do clube?", opts: ["Futebol", "Cricket", "Remo", "Rugby"], answer: 1 },
                { id: "f13", q: "Quantos gols marcou o Vitoria na primeira partida oficial em 1902?", opts: ["1", "2", "3", "4"], answer: 1 },
                { id: "f14", q: "O nome oficial do estadio do Vitoria e:", opts: ["Estadio Roberto Santos", "Estadio Manoel Barradas", "Estadio Octavio Mangabeira", "Estadio Pituacu"], answer: 1 },
                { id: "f15", q: "Qual rival do Vitoria divide o titulo de maior vencedor da Copa do Nordeste?", opts: ["Sport", "Fortaleza", "Ceara", "Bahia"], answer: 3 },
            ]
        },
        {
            name: "Historiador",
            color: "#1D9E75",
            icon: "3",
            timePerQuestion: 16,
            perGame: 5,
            questions: [
                { id: "h01", q: "Quem foram os irmaos fundadores do Vitoria?", opts: ["Artur e Artemio Valente", "Jose e Carlos Ferreira", "Pedro e Paulo Santos", "Fernando e Ricardo Kock"], answer: 0 },
                { id: "h02", q: "Qual esporte o Vitoria praticava originalmente?", opts: ["Futebol", "Polo aquatico", "Cricket", "Remo"], answer: 2 },
                { id: "h03", q: "Por que o apelido 'Leao da Barra' surgiu?", opts: ["Pelo bairro da Barra", "Pelos remadores que sairam do Porto da Barra", "Por um leao no zoologico", "Por uma estatua na praia"], answer: 1 },
                { id: "h04", q: "Quais foram as cores originais do Vitoria antes do rubro-negro?", opts: ["Azul e branco", "Verde e amarelo", "Preto e branco", "Vermelho e branco"], answer: 2 },
                { id: "h05", q: "Quem sugeriu a mudanca para as cores vermelho e preto?", opts: ["Artemio Valente", "Zuza Ferreira", "Cesar Godinho Spinola", "Fernando Kock"], answer: 2 },
                { id: "h06", q: "Em que ano foram os dois primeiros titulos baianos do Vitoria?", opts: ["1905 e 1906", "1908 e 1909", "1910 e 1911", "1912 e 1913"], answer: 1 },
                { id: "h07", q: "Cesar Godinho Spinola veio de qual clube?", opts: ["Vasco", "Fluminense", "Flamengo", "Botafogo"], answer: 2 },
                { id: "h08", q: "Quantos anos o Vitoria ficou sem titulo entre 1910 e 1952?", opts: ["32", "38", "42", "50"], answer: 2 },
                { id: "h09", q: "A reuniao de fundacao do Vitoria quase ocorreu em qual data?", opts: ["1 de maio", "7 de maio", "10 de maio", "15 de maio"], answer: 1 },
                { id: "h10", q: "Por que a fundacao foi adiada para 13 de maio?", opts: ["Feriado nacional", "Chuva em Salvador", "Doenca dos fundadores", "Conflito politico"], answer: 1 },
                { id: "h11", q: "Quem foi o primeiro presidente do Vitoria?", opts: ["Fernando Kock", "Artemio Valente", "Arthur Valente", "Jorge Wilcox"], answer: 1 },
                { id: "h12", q: "De onde os irmaos Valente trouxeram o gosto pelo cricket?", opts: ["Estados Unidos", "Franca", "Inglaterra", "Portugal"], answer: 2 },
                { id: "h13", q: "Quantas pessoas participaram da reuniao de fundacao?", opts: ["12", "15", "19", "25"], answer: 2 },
                { id: "h14", q: "Em que ano o nome virou Sport Club Victoria?", opts: ["1899", "1901", "1902", "1905"], answer: 2 },
                { id: "h15", q: "Em que ano o nome virou Esporte Clube Vitoria?", opts: ["1930", "1940", "1946", "1950"], answer: 2 },
            ]
        },
        {
            name: "Craque",
            color: "#C41E2A",
            icon: "4",
            timePerQuestion: 14,
            perGame: 5,
            questions: [
                { id: "c01", q: "Em que ano o Vitoria foi vice-campeao da Copa do Brasil?", opts: ["2004", "2008", "2010", "2012"], answer: 2 },
                { id: "c02", q: "Qual a melhor colocacao do Vitoria no Brasileirao Serie A?", opts: ["3o lugar", "2o lugar", "4o lugar", "5o lugar"], answer: 1 },
                { id: "c03", q: "Em que ano foi o vice do Brasileirao?", opts: ["1990", "1993", "1997", "1999"], answer: 1 },
                { id: "c04", q: "Quantos titulos baianos o Vitoria possui ao todo?", opts: ["25", "28", "30", "32"], answer: 2 },
                { id: "c05", q: "Em que ano o Vitoria conquistou o titulo da Serie B?", opts: ["2020", "2021", "2022", "2023"], answer: 3 },
                { id: "c06", q: "Em que ano o Vitoria foi 3o colocado no Brasileirao?", opts: ["1993", "1997", "1999", "2001"], answer: 2 },
                { id: "c07", q: "Em que ano o Vitoria chegou as semifinais da Copa do Brasil pela primeira vez?", opts: ["2002", "2004", "2006", "2008"], answer: 1 },
                { id: "c08", q: "Qual o ultimo titulo baiano conquistado pelo Vitoria?", opts: ["2022", "2023", "2024", "2021"], answer: 2 },
                { id: "c09", q: "Em que ano o Vitoria foi vice-campeao da Serie B?", opts: ["1988", "1992", "1995", "2000"], answer: 1 },
                { id: "c10", q: "Quantas vezes o Vitoria foi campeao baiano nas ultimas 20 edicoes?", opts: ["5", "6", "7", "8"], answer: 3 },
                { id: "c11", q: "O Vitoria e o unico clube baiano a chegar em qual torneio?", opts: ["Final da Libertadores", "Final da Copa do Brasil", "Final da Copa Sul-Americana", "Final do Mundial"], answer: 1 },
                { id: "c12", q: "Em que ano o Vitoria disputou a Copa Conmebol?", opts: ["1995", "1997", "1999", "2001"], answer: 1 },
                { id: "c13", q: "Em que rodada o Vitoria chegou na Copa Conmebol de 1997?", opts: ["Oitavas", "Quartas", "Semifinal", "Final"], answer: 1 },
                { id: "c14", q: "Em que ano o Vitoria chegou nas oitavas da Copa Sul-Americana pela primeira vez?", opts: ["2005", "2007", "2009", "2011"], answer: 2 },
                { id: "c15", q: "O Vitoria foi vice-campeao brasileiro em 1993 ficando atras de qual clube?", opts: ["Flamengo", "Sao Paulo", "Palmeiras", "Corinthians"], answer: 2 },
            ]
        },
        {
            name: "Lenda",
            color: "#9B1620",
            icon: "5",
            timePerQuestion: 12,
            perGame: 5,
            questions: [
                { id: "l01", q: "Qual bairro de Salvador deu origem ao nome do clube?", opts: ["Barra", "Pituba", "Corredor da Vitoria", "Itapagipe"], answer: 2 },
                { id: "l02", q: "Qual foi o primeiro adversario do Vitoria no futebol?", opts: ["Bahia", "Sport Club Internacional", "Ypiranga", "Fluminense de Feira"], answer: 1 },
                { id: "l03", q: "Em que competicao da CONMEBOL o Vitoria chegou as quartas?", opts: ["Copa Libertadores", "Copa Sul-Americana", "Copa CONMEBOL 1997", "Recopa"], answer: 2 },
                { id: "l04", q: "Em que ano o Vitoria mudou de Club de Cricket para Sport Club?", opts: ["1899", "1900", "1901", "1902"], answer: 3 },
                { id: "l05", q: "Qual o nome oficial do Barradao?", opts: ["Estadio Roberto Santos", "Estadio Manoel Barradas", "Estadio Octavio Mangabeira", "Estadio Antonio Carlos Magalhaes"], answer: 1 },
                { id: "l06", q: "Qual barco do Vitoria participou da travessia historica em 1902?", opts: ["Tupy", "Tamoio", "Xavante", "Cariri"], answer: 0 },
                { id: "l07", q: "Os remadores do Vitoria foram do Porto da Barra ate qual local?", opts: ["Porto de Itaparica", "Porto dos Tainheiros", "Porto de Salvador", "Porto da Ribeira"], answer: 1 },
                { id: "l08", q: "Quem promoveu o primeiro 'baba' de futebol em Salvador?", opts: ["Artemio Valente", "Zuza Ferreira", "Fernando Kock", "Octavio Rabelo"], answer: 1 },
                { id: "l09", q: "Em que ano Zuza Ferreira voltou da Inglaterra trazendo o futebol?", opts: ["1899", "1900", "1901", "1902"], answer: 2 },
                { id: "l10", q: "Qual foi o adversario da primeira partida oficial do Vitoria?", opts: ["Bahia", "Sport Club Internacional", "Sao Paulo Bahia Football Clube", "Clube Atletico Bahiano"], answer: 2 },
                { id: "l11", q: "Qual era o 'privilegio' dado aos brasileiros no cricket antes do Vitoria?", opts: ["Assistir", "Arbitrar", "Repor bolas", "Vender bilhetes"], answer: 2 },
                { id: "l12", q: "Em que ano o Vitoria conquistou o Trofeu Cidade de Valladolid?", opts: ["1992", "1995", "1997", "2000"], answer: 2 },
                { id: "l13", q: "Em que pais fica Valladolid?", opts: ["Italia", "Portugal", "Franca", "Espanha"], answer: 3 },
                { id: "l14", q: "O Torneio Senegal-Brasil vencido pelo Vitoria foi em qual ano?", opts: ["1990", "1992", "1994", "1996"], answer: 1 },
                { id: "l15", q: "Qual o primeiro campo de futebol usado pelo Vitoria em Salvador?", opts: ["Campo da Graca", "Campo da Polvora", "Campo do Rio Vermelho", "Campo da Barra"], answer: 1 },
            ]
        },
        {
            name: "Imortal",
            color: "#1A1A1A",
            icon: "6",
            timePerQuestion: 10,
            perGame: 5,
            questions: [
                { id: "i01", q: "Em que ano o Vitoria terminou em 5o no Brasileirao, melhor nordestino ate entao?", opts: ["2010", "2011", "2013", "2014"], answer: 2 },
                { id: "i02", q: "Qual torneio internacional o Vitoria venceu em 1992?", opts: ["Copa Kirin", "Trofeu Cidade de Valladolid", "Torneio Senegal-Brasil", "Copa Suruga"], answer: 2 },
                { id: "i03", q: "Quem trouxe o futebol para a Bahia, tendo jogado pelo Victoria depois?", opts: ["Artemio Valente", "Zuza Ferreira", "Fernando Kock", "Cesar Spinola"], answer: 1 },
                { id: "i04", q: "Quando foi a primeira partida oficial de futebol do Victoria?", opts: ["13 maio 1899", "22 maio 1901", "13 setembro 1902", "5 marco 1905"], answer: 2 },
                { id: "i05", q: "Onde o Vitoria disputou sua primeira partida de futebol?", opts: ["Barradao", "Fonte Nova", "Campo da Polvora", "Praia da Barra"], answer: 2 },
                { id: "i06", q: "Quantos membros se reuniram no casarao dos Valente em 13/05/1899?", opts: ["15", "17", "19", "21"], answer: 2 },
                { id: "i07", q: "Qual o nome completo do apelido dos torcedores rubro-negros?", opts: ["Leoes Rubros", "Leoes da Barra", "Leoes Feros", "Leoes Imortais"], answer: 1 },
                { id: "i08", q: "Qual foi o resultado da primeira partida oficial do Vitoria em 1902?", opts: ["1x0", "2x0", "3x1", "2x2"], answer: 1 },
                { id: "i09", q: "Em quantos esportes da Bahia o Vitoria foi pioneiro?", opts: ["3", "5", "7", "9 ou mais"], answer: 3 },
                { id: "i10", q: "Em quantos anos o Vitoria ficou invicto no polo aquatico?", opts: ["1", "2", "3", "4"], answer: 1 },
                { id: "i11", q: "O primeiro campeonato baiano foi disputado em qual ano?", opts: ["1903", "1904", "1905", "1906"], answer: 2 },
                { id: "i12", q: "O Vitoria foi um dos fundadores de qual liga em 1904?", opts: ["Liga Baiana", "Liga Bahiana de Desportos Terrestres", "Federacao Baiana", "Confederacao Nordestina"], answer: 1 },
                { id: "i13", q: "Em que competicao o Vitoria chegou nas quartas de final em 1997?", opts: ["Copa Libertadores", "Copa Sul-Americana", "Copa Conmebol", "Copa Mercosul"], answer: 2 },
                { id: "i14", q: "Quando o Vitoria chegou nas oitavas da Copa Sul-Americana pela segunda vez?", opts: ["2011", "2013", "2014", "2016"], answer: 2 },
                { id: "i15", q: "Qual o nome do campo onde o Vitoria disputou sua primeira partida nao oficial em 1901?", opts: ["Campo da Barra", "Campo da Polvora", "Campo da Graca", "Campo do Carmo"], answer: 1 },
            ]
        },
    ];

    const TOTAL_QUESTIONS = LEVELS.reduce(function(sum, l) { return sum + (l.perGame || 5); }, 0);

    // ============================================================
    // STATE
    // ============================================================
    let state = {
        screen: 'login',
        user: null,
        currentLevel: 0,
        currentQuestion: 0,
        score: 0,
        timeLeft: 0,
        timerInterval: null,
        startTime: 0,
        totalTime: 0,
        answers: [],
        selectedAnswer: null,
        showResult: false,
        quizToken: '',
        ranking: [],
        savedLevel: 0,
        bestScore: 0,
        attempts: 0,
        seenQuestions: [],
        currentRoundQuestions: [],
    };

    // ============================================================
    // GOOGLE LOGIN
    // ============================================================
    function initGoogleLogin() {
        if (!CFG.googleClientId || !window.google) {
            setTimeout(initGoogleLogin, 500);
            return;
        }

        google.accounts.id.initialize({
            client_id: CFG.googleClientId,
            callback: handleGoogleResponse,
        });

        google.accounts.id.renderButton(
            document.getElementById('ldb-google-btn'),
            {
                theme: 'outline',
                size: 'large',
                text: 'continue_with',
                shape: 'rectangular',
                width: 300,
                locale: 'pt-BR',
            }
        );
    }

    function handleGoogleResponse(response) {
        var payload = JSON.parse(atob(response.credential.split('.')[1]));
        state.user = {
            email: payload.email,
            name: payload.name,
            avatar: payload.picture,
            google_id: payload.sub,
        };
        state.screen = 'intro';
        render();
        loadProgress();
    }

    async function loadProgress() {
        try {
            var res = await fetch(API + 'progress?email=' + encodeURIComponent(state.user.email));
            var data = await res.json();
            state.savedLevel = data.max_level || 0;
            state.bestScore = data.best_score || 0;
            state.attempts = data.attempts || 0;
            state.seenQuestions = data.seen || [];
            render();
        } catch (e) {
            state.savedLevel = 0;
            state.bestScore = 0;
            state.attempts = 0;
            state.seenQuestions = [];
        }
    }

    async function saveSeenQuestions() {
        if (!state.user || !state.currentRoundQuestions || state.currentRoundQuestions.length === 0) return;

        var toSave = [];
        state.answers.forEach(function(a) {
            var ql = LEVELS[a.level];
            var q = state.currentRoundQuestions[a.level] && state.currentRoundQuestions[a.level][a.question];
            if (q && q.id) {
                toSave.push({ id: q.id, correct: a.correct ? 1 : 0 });
            }
        });

        if (toSave.length === 0) return;

        try {
            await fetch(API + 'seen', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email: state.user.email,
                    questions: toSave,
                }),
            });

            toSave.forEach(function(q) {
                if (state.seenQuestions.indexOf(q.id) === -1) {
                    state.seenQuestions.push(q.id);
                }
            });
        } catch (e) {
            console.warn('Save seen error:', e);
        }
    }

    function pickQuestionsForLevel(levelIndex) {
        var level = LEVELS[levelIndex];
        var perGame = level.perGame || 5;
        var seen = state.seenQuestions || [];

        // Filter out seen questions
        var unseen = level.questions.filter(function(q) { return seen.indexOf(q.id) === -1; });

        // If not enough unseen questions, reset seen for this level
        if (unseen.length < perGame) {
            // Use all questions but prioritize unseen
            var allSorted = level.questions.slice().sort(function(a, b) {
                var aSeen = seen.indexOf(a.id) !== -1 ? 1 : 0;
                var bSeen = seen.indexOf(b.id) !== -1 ? 1 : 0;
                return aSeen - bSeen;
            });
            unseen = allSorted;
        }

        // Shuffle and pick perGame
        var shuffled = unseen.slice().sort(function() { return Math.random() - 0.5; });
        return shuffled.slice(0, perGame);
    }

    // ============================================================
    // TIMER
    // ============================================================
    function startTimer() {
        clearInterval(state.timerInterval);
        const level = LEVELS[state.currentLevel];
        state.timeLeft = level.timePerQuestion;

        state.timerInterval = setInterval(() => {
            state.timeLeft--;
            updateTimerDisplay();

            if (state.timeLeft <= 0) {
                clearInterval(state.timerInterval);
                handleAnswer(-1);
            }
        }, 1000);
    }

    function updateTimerDisplay() {
        const el = document.getElementById('qz-timer');
        if (!el) return;
        const pct = (state.timeLeft / LEVELS[state.currentLevel].timePerQuestion) * 100;
        const color = pct > 50 ? '#0F6E56' : pct > 25 ? '#D4A843' : '#C41E2A';
        el.style.width = pct + '%';
        el.style.background = color;

        const num = document.getElementById('qz-timer-num');
        if (num) {
            num.textContent = state.timeLeft + 's';
            num.style.color = color;
        }
    }

    // ============================================================
    // GAME LOGIC
    // ============================================================
    function startQuiz(fromLevel) {
        var startLevel = fromLevel || 0;

        // Pick fresh questions for each level (filtering out seen ones)
        state.currentRoundQuestions = LEVELS.map(function(l, i) {
            return pickQuestionsForLevel(i);
        });

        state.screen = 'playing';
        state.currentLevel = startLevel;
        state.currentQuestion = 0;
        state.score = startLevel * 5;
        state.answers = [];
        state.startTime = Date.now();
        state.quizToken = btoa(Date.now() + '_' + Math.random());

        // Fill in answers for skipped levels
        for (var l = 0; l < startLevel; l++) {
            for (var q = 0; q < (LEVELS[l].perGame || 5); q++) {
                state.answers.push({ level: l, question: q, selected: 0, correct: true, timeLeft: 0 });
            }
        }

        render();
        startTimer();
    }

    function handleAnswer(answerIndex) {
        clearInterval(state.timerInterval);

        var levelQuestions = state.currentRoundQuestions[state.currentLevel];
        var question = levelQuestions[state.currentQuestion];
        var correct = answerIndex === question.answer;

        if (correct) state.score++;

        state.answers.push({
            level: state.currentLevel,
            question: state.currentQuestion,
            selected: answerIndex,
            correct: correct,
            timeLeft: state.timeLeft,
        });

        state.selectedAnswer = answerIndex;
        state.showResult = true;
        render();

        setTimeout(function() {
            state.showResult = false;
            state.selectedAnswer = null;

            var nextQ = state.currentQuestion + 1;
            if (nextQ >= levelQuestions.length) {
                if (!correct) {
                    finishQuiz(false);
                    return;
                }
                var nextLevel = state.currentLevel + 1;
                if (nextLevel >= LEVELS.length) {
                    finishQuiz(true);
                    return;
                }
                state.currentLevel = nextLevel;
                state.currentQuestion = 0;
                state.screen = 'level-up';
                render();
            } else {
                if (!correct) {
                    finishQuiz(false);
                    return;
                }
                state.currentQuestion = nextQ;
                render();
                startTimer();
            }
        }, correct ? 1200 : 2000);
    }

    function continueAfterLevelUp() {
        state.screen = 'playing';
        render();
        startTimer();
    }

    async function finishQuiz(completed) {
        state.totalTime = Math.round((Date.now() - state.startTime) / 1000);
        state.screen = 'result';

        const answered = state.answers.length;
        const percentage = answered > 0 ? Math.round((state.score / TOTAL_QUESTIONS) * 100) : 0;

        render();

        // Save seen questions (anti-cheat) - fire and forget
        saveSeenQuestions();

        try {
            const res = await fetch(API + 'save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email: state.user.email,
                    name: state.user.name,
                    avatar: state.user.avatar,
                    google_id: state.user.google_id,
                    score: state.score,
                    total: TOTAL_QUESTIONS,
                    level_reached: state.currentLevel + 1,
                    time_spent: state.totalTime,
                    quiz_token: state.quizToken,
                }),
            });

            const data = await res.json();

            if (data.reward) {
                const rewardEl = document.getElementById('qz-reward');
                if (rewardEl) rewardEl.innerHTML = data.reward;
            }

            const posEl = document.getElementById('qz-position');
            if (posEl && data.position) posEl.textContent = '#' + data.position;
        } catch (e) {
            console.warn('Quiz save error:', e);
        }

        loadRanking();
    }

    async function loadRanking() {
        try {
            const res = await fetch(API + 'ranking');
            const data = await res.json();
            state.ranking = data || [];
            renderRanking();
        } catch (e) {
            console.warn('Ranking error:', e);
        }
    }

    function renderRanking() {
        const el = document.getElementById('qz-ranking-body');
        if (!el || !state.ranking.length) return;

        let html = '';
        state.ranking.forEach((r, i) => {
            const medal = i === 0 ? '&#x1F947;' : i === 1 ? '&#x1F948;' : i === 2 ? '&#x1F949;' : (i + 1);
            const isMe = state.user && r.user_name === state.user.name;
            html += `<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f0f0f0;${isMe ? 'background:#FDF0F1;margin:0 -12px;padding:8px 12px;border-radius:6px;' : ''}">`;
            html += `<span style="font-family:Oswald,sans-serif;font-size:14px;font-weight:700;min-width:28px;text-align:center;">${medal}</span>`;
            if (r.user_avatar) html += `<img src="${r.user_avatar}" width="28" height="28" style="border-radius:50%;">`;
            html += `<div style="flex:1;min-width:0"><div style="font-family:Oswald,sans-serif;font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(r.user_name)}</div>`;
            html += `<div style="font-size:11px;color:#999">${r.percentage}% &middot; Nivel ${r.level_reached}</div></div>`;
            html += `<span style="font-family:Oswald,sans-serif;font-size:12px;color:#999">${formatTime(r.time_spent)}</span>`;
            html += '</div>';
        });
        el.innerHTML = html;
    }

    // ============================================================
    // HELPERS
    // ============================================================
    function esc(str) { const d = document.createElement('div'); d.textContent = str || ''; return d.innerHTML; }
    function formatTime(s) { return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); }

    // ============================================================
    // RENDER
    // ============================================================
    function render() {
        let html = '';

        if (state.screen === 'login') {
            html = renderLogin();
        } else if (state.screen === 'intro') {
            html = renderIntro();
        } else if (state.screen === 'playing') {
            html = renderPlaying();
        } else if (state.screen === 'level-up') {
            html = renderLevelUp();
        } else if (state.screen === 'result') {
            html = renderResult();
        }

        root.innerHTML = html;
        bindEvents();

        if (state.screen === 'login') initGoogleLogin();
    }

    function renderLogin() {
        return `
        <div style="text-align:center;padding:40px 16px">
            <div style="width:80px;height:80px;background:#C41E2A;border-radius:50%;margin:0 auto 16px;display:flex;align-items:center;justify-content:center">
                <span style="font-family:Oswald,sans-serif;font-size:32px;font-weight:700;color:#fff">V</span>
            </div>
            <h1 style="font-family:Oswald,sans-serif;font-size:28px;font-weight:700;color:#1A1A1A;text-transform:uppercase;margin:0 0 6px">Quiz do <span style="color:#C41E2A">Vitoria</span></h1>
            <p style="font-family:Source Sans 3,sans-serif;font-size:15px;color:#888;margin:0 0 24px;line-height:1.5">
                30 perguntas em 6 niveis. Quanto voce sabe sobre o Leao da Barra?
                Acerte 100% e ganhe uma recompensa exclusiva!
            </p>
            <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:28px">
                ${LEVELS.map(l => `<span style="font-family:Oswald,sans-serif;font-size:10px;background:${l.color};color:#fff;padding:3px 10px;border-radius:12px;text-transform:uppercase;letter-spacing:0.5px">${l.name}</span>`).join('')}
            </div>
            <div style="background:#F7F7F7;border-radius:10px;padding:20px;margin-bottom:20px">
                <p style="font-family:Source Sans 3,sans-serif;font-size:13px;color:#666;margin:0 0 16px">Entre com sua conta Google para jogar e salvar seu recorde</p>
                <div id="ldb-google-btn" style="display:flex;justify-content:center"></div>
            </div>
            <div style="font-size:11px;color:#bbb;line-height:1.5">
                Cada nivel fica mais dificil e o tempo diminui.<br>
                Erre uma e o jogo acaba. Voce tem coragem?
            </div>
        </div>`;
    }

    function renderIntro() {
        var sl = state.savedLevel || 0;
        var canContinue = sl > 0 && sl < LEVELS.length;
        var completedAll = sl >= LEVELS.length;
        var firstName = esc(state.user.name.split(' ')[0]);

        var progressHtml = '';
        if (state.attempts > 0) {
            progressHtml = '<div style="background:#E1F5EE;border-radius:8px;padding:12px 16px;margin-bottom:16px;text-align:center">';
            progressHtml += '<div style="font-family:Oswald,sans-serif;font-size:12px;color:#0F6E56;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Seu progresso</div>';
            progressHtml += '<div style="font-family:Oswald,sans-serif;font-size:22px;font-weight:700;color:#0F6E56">' + (completedAll ? 'Todos os niveis completos!' : 'Nivel ' + (sl + 1) + ' - ' + LEVELS[Math.min(sl, LEVELS.length - 1)].name) + '</div>';
            progressHtml += '<div style="font-size:12px;color:#555;margin-top:4px">' + state.attempts + ' tentativa' + (state.attempts > 1 ? 's' : '') + ' &middot; Melhor: ' + state.bestScore + '%</div>';
            progressHtml += '</div>';
        }

        var buttonsHtml = '';
        if (canContinue) {
            buttonsHtml += '<button id="qz-continue-saved" style="font-family:Oswald,sans-serif;font-size:16px;font-weight:700;padding:14px 40px;background:#C41E2A;color:#fff;border:none;border-radius:8px;cursor:pointer;text-transform:uppercase;letter-spacing:0.5px;width:100%;margin-bottom:8px">Continuar do nivel ' + LEVELS[sl].name + '</button>';
            buttonsHtml += '<button id="qz-start" style="font-family:Oswald,sans-serif;font-size:13px;font-weight:600;padding:10px 20px;background:none;color:#888;border:1px solid #ddd;border-radius:8px;cursor:pointer;text-transform:uppercase;width:100%">Comecar do zero</button>';
        } else {
            buttonsHtml += '<button id="qz-start" style="font-family:Oswald,sans-serif;font-size:16px;font-weight:700;padding:14px 40px;background:#C41E2A;color:#fff;border:none;border-radius:8px;cursor:pointer;text-transform:uppercase;letter-spacing:0.5px;width:100%">' + (completedAll ? 'Jogar novamente' : 'Comecar o Desafio') + '</button>';
        }

        return '<div style="text-align:center;padding:40px 16px">' +
            '<img src="' + esc(state.user.avatar) + '" width="64" height="64" style="border-radius:50%;margin:0 auto 12px;display:block;border:3px solid #C41E2A">' +
            '<h2 style="font-family:Oswald,sans-serif;font-size:22px;font-weight:700;color:#1A1A1A;margin:0 0 4px">' + (canContinue ? 'Bem-vindo de volta, ' + firstName + '!' : 'Pronto, ' + firstName + '?') + '</h2>' +
            '<p style="font-family:Source Sans 3,sans-serif;font-size:14px;color:#888;margin:0 0 16px">' +
                '6 niveis, 5 perguntas cada. O tempo diminui a cada nivel.<br>' +
                '<strong style="color:#C41E2A">Errou = Game Over.</strong> Sem segunda chance.' +
            '</p>' +
            progressHtml +
            '<div style="background:#F7F7F7;border-radius:10px;padding:16px;margin-bottom:20px;text-align:left">' +
                LEVELS.map(function(l, i) {
                    var done = i < sl;
                    var current = i === sl && canContinue;
                    var statusIcon = done ? '&#x2705;' : current ? '&#x25B6;' : '&#x1F512;';
                    var opacity = done ? 'opacity:0.6;' : '';
                    return '<div style="display:flex;align-items:center;gap:10px;padding:6px 0;' + (i < LEVELS.length - 1 ? 'border-bottom:1px solid #eee;' : '') + opacity + '">' +
                        '<span style="width:28px;height:28px;border-radius:50%;background:' + l.color + ';color:#fff;display:flex;align-items:center;justify-content:center;font-family:Oswald,sans-serif;font-size:12px;font-weight:700;flex-shrink:0">' + l.icon + '</span>' +
                        '<span style="font-family:Oswald,sans-serif;font-size:13px;font-weight:600;flex:1;text-transform:uppercase">' + l.name + '</span>' +
                        '<span style="font-size:14px">' + statusIcon + '</span>' +
                        '<span style="font-family:Source Sans 3,sans-serif;font-size:11px;color:#999">' + l.timePerQuestion + 's</span>' +
                    '</div>';
                }).join('') +
            '</div>' +
            buttonsHtml +
        '</div>';
    }

    function renderPlaying() {
        const level = LEVELS[state.currentLevel];
        const question = state.currentRoundQuestions[state.currentLevel][state.currentQuestion];
        const globalQ = state.answers.length + 1;
        const pct = (state.timeLeft / level.timePerQuestion) * 100;
        const timerColor = pct > 50 ? '#0F6E56' : pct > 25 ? '#D4A843' : '#C41E2A';

        return `
        <div style="padding:12px 0">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="width:28px;height:28px;border-radius:50%;background:${level.color};color:#fff;display:flex;align-items:center;justify-content:center;font-family:Oswald,sans-serif;font-size:12px;font-weight:700">${level.icon}</span>
                    <span style="font-family:Oswald,sans-serif;font-size:14px;font-weight:600;text-transform:uppercase;color:${level.color}">${level.name}</span>
                </div>
                <div style="text-align:right">
                    <span style="font-family:Oswald,sans-serif;font-size:12px;color:#999">${globalQ}/${TOTAL_QUESTIONS}</span>
                    <span id="qz-timer-num" style="font-family:Oswald,sans-serif;font-size:16px;font-weight:700;margin-left:8px;color:${timerColor}">${state.timeLeft}s</span>
                </div>
            </div>

            <div style="height:4px;background:#eee;border-radius:2px;margin-bottom:20px;overflow:hidden">
                <div id="qz-timer" style="height:100%;width:${pct}%;background:${timerColor};border-radius:2px;transition:width 1s linear"></div>
            </div>

            <div style="background:#F7F7F7;border-radius:10px;padding:20px;margin-bottom:16px">
                <div style="font-family:Oswald,sans-serif;font-size:10px;color:${level.color};text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Pergunta ${state.currentQuestion + 1} de ${level.perGame || 5}</div>
                <h2 style="font-family:Oswald,sans-serif;font-size:18px;font-weight:600;color:#1A1A1A;margin:0;line-height:1.3">${esc(question.q)}</h2>
            </div>

            <div style="display:flex;flex-direction:column;gap:8px">
                ${question.opts.map((opt, i) => {
                    let bg = '#fff', border = '1px solid #E5E5E5', color = '#1A1A1A', extra = '';
                    if (state.showResult) {
                        if (i === question.answer) {
                            bg = '#E1F5EE'; border = '2px solid #0F6E56'; color = '#0F6E56';
                        } else if (i === state.selectedAnswer && i !== question.answer) {
                            bg = '#FDF0F1'; border = '2px solid #C41E2A'; color = '#C41E2A';
                        } else {
                            extra = 'opacity:0.4;';
                        }
                    }
                    return `<button data-answer="${i}" style="font-family:Source Sans 3,sans-serif;font-size:15px;font-weight:500;padding:14px 16px;background:${bg};border:${border};border-radius:8px;cursor:pointer;text-align:left;color:${color};transition:all 0.15s;${extra}${state.showResult ? 'pointer-events:none;' : ''}" ${!state.showResult ? 'onmouseover="this.style.background=\'#F7F7F7\'" onmouseout="this.style.background=\'#fff\'"' : ''}>${String.fromCharCode(65 + i)}.  ${esc(opt)}</button>`;
                }).join('')}
            </div>

            <div style="display:flex;justify-content:center;gap:4px;margin-top:16px">
                ${LEVELS.map((l, i) => {
                    const done = i < state.currentLevel;
                    const active = i === state.currentLevel;
                    return `<div style="width:${active ? '24px' : '8px'};height:8px;border-radius:4px;background:${done ? l.color : active ? l.color : '#ddd'};transition:all 0.3s"></div>`;
                }).join('')}
            </div>
        </div>`;
    }

    function renderLevelUp() {
        const prevLevel = LEVELS[state.currentLevel - 1];
        const nextLevel = LEVELS[state.currentLevel];

        return `
        <div style="text-align:center;padding:40px 16px">
            <div style="font-size:48px;margin-bottom:12px">&#x1F525;</div>
            <h2 style="font-family:Oswald,sans-serif;font-size:24px;font-weight:700;color:#1A1A1A;margin:0 0 4px;text-transform:uppercase">Nivel ${state.currentLevel} completo!</h2>
            <p style="font-family:Source Sans 3,sans-serif;font-size:14px;color:#888;margin:0 0 20px">
                Voce completou o nivel <strong style="color:${prevLevel.color}">${prevLevel.name}</strong>
            </p>
            <div style="background:#F7F7F7;border-radius:10px;padding:16px;margin-bottom:24px">
                <div style="font-family:Oswald,sans-serif;font-size:12px;color:#999;text-transform:uppercase;margin-bottom:8px">Proximo nivel</div>
                <div style="display:flex;align-items:center;gap:10px;justify-content:center">
                    <span style="width:36px;height:36px;border-radius:50%;background:${nextLevel.color};color:#fff;display:flex;align-items:center;justify-content:center;font-family:Oswald,sans-serif;font-size:16px;font-weight:700">${nextLevel.icon}</span>
                    <div style="text-align:left">
                        <div style="font-family:Oswald,sans-serif;font-size:18px;font-weight:700;color:${nextLevel.color};text-transform:uppercase">${nextLevel.name}</div>
                        <div style="font-family:Source Sans 3,sans-serif;font-size:12px;color:#999">${nextLevel.timePerQuestion}s por pergunta</div>
                    </div>
                </div>
            </div>
            <div style="font-family:Oswald,sans-serif;font-size:32px;font-weight:700;color:#C41E2A;margin-bottom:4px">${state.score}/${state.answers.length}</div>
            <div style="font-family:Source Sans 3,sans-serif;font-size:13px;color:#999;margin-bottom:20px">acertos ate agora</div>
            <button id="qz-continue" style="font-family:Oswald,sans-serif;font-size:16px;font-weight:700;padding:14px 40px;background:${nextLevel.color};color:#fff;border:none;border-radius:8px;cursor:pointer;text-transform:uppercase;width:100%">Continuar</button>
        </div>`;
    }

    function renderResult() {
        const percentage = state.answers.length > 0 ? Math.round((state.score / TOTAL_QUESTIONS) * 100) : 0;
        const isWinner = percentage === 100;
        const levelReached = state.currentLevel + 1;
        const levelData = LEVELS[Math.min(state.currentLevel, LEVELS.length - 1)];

        let emoji = '&#x1F622;'; let msg = 'Tente novamente!';
        if (percentage === 100) { emoji = '&#x1F3C6;'; msg = 'PERFEITO! Voce e um Imortal!'; }
        else if (percentage >= 80) { emoji = '&#x1F929;'; msg = 'Quase la! Impressionante!'; }
        else if (percentage >= 60) { emoji = '&#x1F44F;'; msg = 'Muito bem! Continue tentando!'; }
        else if (percentage >= 40) { emoji = '&#x1F914;'; msg = 'Nada mal, mas pode melhor!'; }
        else if (percentage >= 20) { emoji = '&#x1F61F;'; msg = 'Estude mais sobre o Leao!'; }

        const shareText = `Fiz ${state.score}/${TOTAL_QUESTIONS} (${percentage}%) no Quiz do Vitoria! Nivel: ${levelData.name}. Consegue me superar? ${CFG.siteUrl}quiz-do-vitoria/`;

        return `
        <div style="text-align:center;padding:30px 16px">
            <div style="font-size:56px;margin-bottom:8px">${emoji}</div>
            <h2 style="font-family:Oswald,sans-serif;font-size:22px;font-weight:700;color:#1A1A1A;margin:0 0 4px;text-transform:uppercase">${msg}</h2>
            <p style="font-family:Source Sans 3,sans-serif;font-size:13px;color:#888;margin:0 0 20px">
                Nivel alcancado: <strong style="color:${levelData.color}">${levelData.name}</strong> &middot; Tempo: ${formatTime(state.totalTime)}
            </p>

            <div style="display:flex;gap:8px;justify-content:center;margin-bottom:20px">
                <div style="background:#F7F7F7;border-radius:8px;padding:14px 20px;text-align:center;flex:1">
                    <div style="font-family:Oswald,sans-serif;font-size:28px;font-weight:700;color:#C41E2A">${state.score}/${TOTAL_QUESTIONS}</div>
                    <div style="font-family:Source Sans 3,sans-serif;font-size:11px;color:#999">Acertos</div>
                </div>
                <div style="background:#F7F7F7;border-radius:8px;padding:14px 20px;text-align:center;flex:1">
                    <div style="font-family:Oswald,sans-serif;font-size:28px;font-weight:700;color:#1A1A1A">${percentage}%</div>
                    <div style="font-family:Source Sans 3,sans-serif;font-size:11px;color:#999">Aproveitamento</div>
                </div>
                <div style="background:#F7F7F7;border-radius:8px;padding:14px 20px;text-align:center;flex:1">
                    <div style="font-family:Oswald,sans-serif;font-size:28px;font-weight:700;color:#0F6E56" id="qz-position">-</div>
                    <div style="font-family:Source Sans 3,sans-serif;font-size:11px;color:#999">Ranking</div>
                </div>
            </div>

            ${isWinner ? `
                <div style="background:#E1F5EE;border:2px solid #0F6E56;border-radius:10px;padding:16px;margin-bottom:16px">
                    <div style="font-family:Oswald,sans-serif;font-size:14px;font-weight:700;color:#0F6E56;text-transform:uppercase;margin-bottom:6px">Recompensa Desbloqueada!</div>
                    <div id="qz-reward" style="font-family:Source Sans 3,sans-serif;font-size:14px;color:#333">Carregando...</div>
                </div>
            ` : ''}

            <div style="display:flex;gap:8px;margin-bottom:20px">
                <button id="qz-retry" style="font-family:Oswald,sans-serif;font-size:13px;font-weight:600;padding:10px 20px;background:#1A1A1A;color:#fff;border:none;border-radius:6px;cursor:pointer;text-transform:uppercase;flex:1">Jogar novamente</button>
                <button id="qz-share" style="font-family:Oswald,sans-serif;font-size:13px;font-weight:600;padding:10px 20px;background:#C41E2A;color:#fff;border:none;border-radius:6px;cursor:pointer;text-transform:uppercase;flex:1" data-text="${esc(shareText)}">Desafiar amigos</button>
            </div>

            <div style="background:#F7F7F7;border-radius:10px;padding:14px;text-align:left">
                <div style="font-family:Oswald,sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;margin-bottom:8px;color:#1A1A1A">Ranking</div>
                <div id="qz-ranking-body"><div style="text-align:center;color:#999;font-size:13px;padding:12px">Carregando...</div></div>
            </div>
        </div>`;
    }

    // ============================================================
    // EVENTS
    // ============================================================
    function bindEvents() {
        var startBtn = document.getElementById('qz-start');
        if (startBtn) startBtn.addEventListener('click', function() { startQuiz(0); });

        var continueSavedBtn = document.getElementById('qz-continue-saved');
        if (continueSavedBtn) continueSavedBtn.addEventListener('click', function() {
            startQuiz(state.savedLevel);
        });

        var continueBtn = document.getElementById('qz-continue');
        if (continueBtn) continueBtn.addEventListener('click', continueAfterLevelUp);

        var retryBtn = document.getElementById('qz-retry');
        if (retryBtn) retryBtn.addEventListener('click', function() {
            loadProgress();
            state.screen = 'intro';
            render();
        });

        var shareBtn = document.getElementById('qz-share');
        if (shareBtn) shareBtn.addEventListener('click', function() {
            var text = this.dataset.text;
            if (navigator.share) {
                navigator.share({ title: 'Quiz do Vitoria', text: text });
            } else if (navigator.clipboard) {
                navigator.clipboard.writeText(text);
                alert('Link copiado! Compartilhe com seus amigos.');
            }
        });

        root.querySelectorAll('[data-answer]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (state.showResult) return;
                handleAnswer(parseInt(this.dataset.answer));
            });
        });
    }

    // Init
    render();
})();
