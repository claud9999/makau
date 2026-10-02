const BgaAnimations = await importEsmLib('bga-animations', '1.x');
const BgaCards = await importEsmLib('bga-cards', '1.x');

class GameOn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.onPlayerActivationChange(args, isCurrentPlayerActive);
    }

    onPlayerActivationChange(args, isCurrentPlayerActive) {
        this.game.setPlayOptions();
    }
}

export class Game {
    constructor(bga) {
        this.bga = bga;

        this.bga.states.register('GameOn', new GameOn(this, bga));

        this.bga.states.logger = console.log;

        this.cardTypes = {
            "suit": {
                1: "Spade",
                2: "Heart",
                3: "Club",
                4: "Diamond",
            },
            "suitUnicode": {
                1: "&#9824;",
                2: "&#9829;",
                3: "&#9827;",
                4: "&#9830;",
            },
            "rank": {
                2: "Two",
                3: "Three",
                4: "Four",
                5: "Five",
                6: "Six",
                7: "Seven",
                8: "Eight",
                9: "Nine",
                10: "Ten",
                11: "Jack",
                12: "Queen",
                13: "King",
                14: "Ace",
                15: "Joker"
            },
        }
    }

    tooltip(card) {
        let r = `${this.cardTypes["rank"][card.rank]} ${this.cardTypes["suitUnicode"][card.suit]}`;
        switch (card.rank) {
            case 2:
                r += ' (next player draws 2)';
                break;
            case 3:
                r += ' (next player draws 3)';
                break;
            case 4:
                r += ' (skip next player)';
                break;
            case 11: // J
                r += ' (demand non-action rank of other players)';
                break;
            case 12: // Q
                r += ' (anything on Q, Q on anything)';
                break;
            case 13: // K
                switch (card.suit) {
                    case 1: // S
                        r += ' (previous player draws 5)';
                        break;
                    case 2: // H
                        r += ' (next player draws 5)';
                        break;
                    case 3: // C
                        r += ' (blocks K)';
                        break;
                    case 4: // D
                        r += ' (blocks K)';
                        break;
                }
                break;
            case 14:
                r += ' (wild, demand next player play suit)';
                break;
            case 15:
                r += ' (can be played as any card at any time)';
                break;
        }

        return r;
    }

    setup(args) {
        console.log("Starting game setup");
        this.st = args;
        this.playerId = args.playerId;

        let html = `
            <div id="table">
                <div class="deckdiscardplay">
                    <div style="flex: column; width:20%;">
                        <div id="deck" class="deck"></div>
                        <div id="drawButton"></div>
                    </div>
                    <div style="flex: column; width:60%">
                        <div id="discard" class="discard"></div>
                        <div id="passButton"></div>
                    </div>
                    <div style="flex: column; width:20%;">
                        <div id="play" class="play"></div>
                        <div id="playButton"></div>
                    </div>
                </div><!-- deckdiscardplay -->

                <div class="players">
            `;

        for (let i = 0; i < args.playerIds.length; i++) {
            let playerId = args.playerIds[i];

            if (playerId == this.playerId) continue;

            html += `
                    <div style="flex: column; width:30%;">
                        <div id="hand${playerId}" class="player"></div>
                        <div id="makau${playerId}"></div>
                    </div>
            `;
        }

        html += `
                    <div style="flex: column; flex-grow: 1;">
                        <div id="hand" class="hand"></div>
                        <div align="right" id="makau${this.playerId}"></div>
                    </div>
                </div><!-- players -->
            </div><!-- table -->
            `;

        this.bga.gameArea.getElement().insertAdjacentHTML(
            "beforeend",
            html
        );

        // create the animation manager, and bind it to the `game.bgaAnimationsActive()` function
        this.animationManager = new BgaAnimations.Manager({
            animationsActive: () => this.bga.gameui.bgaAnimationsActive()
        });

        const cardWidth = 100;
        const cardHeight = 135;

        this.cardsManager = new BgaCards.Manager({
            animationManager: this.animationManager,
            type: "ha-card",
            getId: (card) => card.id,

            cardWidth: cardWidth,
            cardHeight: cardHeight,
            cardBorderRadius: "5%",
            setupFrontDiv: (card, div) => {
                div.dataset.suit = card.suit; // 1..4
                div.dataset.rank = card.rank; // value 2..14
                div.style.backgroundPositionX = `calc(100% / 14 * (${card.rank} - 2))`; // 14 is number of columns in stock image minus 1
                div.style.backgroundPositionY = `calc(100% / 3 * (${card.suit} - 1))`; // 3 is number of rows in stock image minus 1
                this.bga.gameui.addTooltipHtml(div.id, this.tooltip(card));
            },
            setupBackDiv: (card, div) => {
                div.style.backgroundPositionX = `100%`;
                div.style.backgroundPositionY = `0%`;
            },
            isCardVisible: (card) => { return card.rank > 0; }
        });

        this.deck = new BgaCards.Deck(this.cardsManager, document.getElementById('deck'), {
            cardNumber: args.deck,
            counter: {
                position: 'center',
                extraClasses: 'text-shadow'
            },
        });

        for (let i = 0; i < args.playerIds.length; i++) {
            let playerId = args.playerIds[i];

            /// stuff to add to all players
            this[`makauButton${playerId}`] = this.bga.statusBar.addActionButton(
                _('Makau'),
                () => {
                    this.callMakau(this.playerId, playerId);
                },
                {
                    'destination': document.getElementById(`makau${playerId}`),

                }
            );

            if (playerId == this.bga.players.getCurrentPlayerId()) continue;

            /// stuff to add to other players

            let hName = `hand${playerId}`;
            this[hName] = new BgaCards.DiscardDeck(
                this.cardsManager,
                document.getElementById(hName),
                {
                    cardOverlap: 75
                }
            );
            this.updateHandSize(playerId, args[hName]);
        }

        this.hand = new BgaCards.HandStock(
            this.cardsManager,
            document.getElementById("hand")
        );
        this.hand.addCards(args.hand);

        this.play = new BgaCards.HandStock(
            this.cardsManager,
            document.getElementById("play"),
            {
                fanShaped: false,
                cardOverlap: 75
            }
        );

        this.play.setSelectionMode('single');

        this.discard = new BgaCards.HandStock(
            this.cardsManager,
            document.getElementById("discard"),
            {
                fanShaped: false,
                cardOverlap: 75
            }
        );

        this.discard.addCards(args.discards);

        this.hand.onCardClick = (card) => {
            if (args.gamestate.name != "GameOn") this.hand.unselectAll();
            this.play.addCard(card);
            this.setPlayOptions();
        }

        this.play.onCardClick = (card) => {
            this.hand.addCard(card);
            this.setPlayOptions();
        }

        this.drawButton = this.bga.statusBar.addActionButton(_('Draw'), () => {
            this.hand.addCards(this.play.getCards());
            this.bga.actions.performAction('actDraw');
            this.hand.setSelectionMode('none');
        }, {
            'destination': document.getElementById("drawButton"),
            'disabled': true
        });

        this.playButton = this.bga.statusBar.addActionButton(_('Play'), () => {
            this.bga.actions.performAction(
                'actPlay', {
                cardIds: this.play.getCards().map((card) => card.id)
            })
        }, {
            'destination': document.getElementById("playButton"),
            'disabled': true
        });

        this.passButton = this.bga.statusBar.addActionButton(_('Pass'), () => {
            this.hand.addCards(this.play.getCards());
            this.bga.actions.performAction('actPass');
            this.hand.setSelectionMode('none');
        }, {
            'destination': document.getElementById("passButton"),
            'disabled': true
        });

        // Setup game notifications to handle (see "setupNotifications" method below)
        this.setupNotifications();

        console.log("Ending game setup");
        this.setPlayOptions();
    }

    enablePlay() {
        this.playButton.disabled = false;
    }

    disablePlay() {
        this.playButton.disabled = true;
    }

    enableDraw() {
        this.drawButton.disabled = false;
        this.deck.setSelectionMode('single');
        this.deck.onCardClick = () => {
            this.hand.addCards(this.play.getCards());
            this.bga.actions.performAction('actDraw');
            this.hand.setSelectionMode('none');
        }
    }

    disableDraw() {
        this.drawButton.disabled = true;
        this.deck.onCardClick = null;
    }

    enablePass() {
        this.passButton.disabled = false;
    }

    disablePass() {
        this.passButton.disabled = true;
    }

    updateHandSize(playerId, count) {
        let h = this[`hand${playerId}`];
        if (h == undefined) return;

        h.removeAll();

        for (let j = 0; j < count; j++) {
            h.addCard({
                id: `player${playerId}card${j}`,
                suit: 0,
                rank: 0
            });
        }
    }

    callMakau(activePlayerId, onPlayerId) {
        if (this.st[`hand${onPlayerId}`] <= 1)
            return this.bga.actions.performAction('actMakau',
                {
                    'currentPlayerId': this.st.playerId,
                    'onPlayerId': onPlayerId,
                });
    }

    setPlayOptions() {
        // conveniences
        let statusBar = this.bga.statusBar;
        let hand = this.hand;
        let handCards = this.hand.cards;
        let playCards = this.play.cards;
        let st = this.st;

        this.disableDraw();
        this.disablePlay();
        this.disablePass();

        let currentPlayerId = this.bga.players.getCurrentPlayerId();

        statusBar.removeActionButtons();

        if (st.fwPlayerId != currentPlayerId && st.bwPlayerId != currentPlayerId) {
            statusBar.setTitle(_("${playerName} is playing now."), {
                "playerName": this.bga.players.getPlayerById(st.fwPlayerId).name,
            });

            hand.setSelectionMode('single', []);
            return;
        }

        if (st[`draw${st.fwPlayerId}`] > 0) {
            ////////////////////// must draw
            setTitle(_('You must draw cards.'));
            this.enableDraw();
            hand.setSelectionMode('single', []);
            return;
        }

        if (st.draw > 0) {
            ////////////////////// draw demand
            let playableCards = [];

            for (let i = 0; i < handCards.length; i++) {
                let card = handCards[i];
                if (card.rank < 4 || card.rank == 13) playableCards.push(card);
            }

            if (playCards.length > 0) {
                statusBar.setTitle('Click play when ready.');
                hand.setSelectionMode('single', playableCards);
                this.enablePlay();
                return;
            }

            if (playableCards.length > 0) {
                statusBar.setTitle('You may add to the draw.')
                hand.setSelectionMode('single', playableCards);
            } else {
                statusBar.setTitle('You must draw.');
            }
            this.enableDraw();
            return;
        }

        if (st.fwPlayerId != currentPlayerId) {
            // bwPlayerId, should never get here!
            hand.setSelectionMode('single', []);
            return;
        }

        if (st.rankPick > 0) {
            ////////////////////// pick rank
            return this.pickRank();
        }

        if (st.suitPick > 0) {
            ////////////////////// pick suit
            return this.pickSuit();
        }

        if (this[`skip${st.fwPlayerId}`] > 0) {
            ////////////////////// must skip
            statusBar.setTitle(_('You must pass.'));
            this.enablePass();
            hand.setSelectionMode('single', []);
            return;
        }

        if (st.skip > 0) {
            ////////////////////// skip demand
            let playableCards = [];
            for (let i = 0; i < handCards.length; i++) {
                let card = handCards[i];
                if (card.rank == 4) playableCards.push(card);
            }

            if (playCards.length > 0) {
                statusBar.setTitle('Click play when ready.');
                hand.setSelectionMode('single', playableCards);
                this.enablePlay();
                return;
            }

            for (let i = 0; i < handCards.length; i++) {
                let card = handCards[i];
                if (card.rank == 4) playableCards.push(card);
            }

            if (playableCards.length > 0) {
                statusBar.setTitle(_('Add to the skip.'));
            } else {
                statusBar.setTitle(_('You have to pass.'));
            }
            this.enablePass();
            hand.setSelectionMode('single', playableCards);
            return;
        }

        if (st.rankDemand > 0) {
            ////////////////////// rank demand
            let playableCards = [];
            for (let i = 0; i < handCards.length; i++) {
                let card = handCards[i];
                if (
                    card.rank == st.rankDemand
                    || st.rankDemand == 20 && card.rank > 4 && card.rank < 11
                    || card.rank == 11
                ) playableCards.push(card);
            }

            if (playCards.length > 0) {
                statusBar.setTitle('Click play when ready.');
                hand.setSelectionMode('single', playableCards);
                this.enablePlay();
                return;
            }

            if (playableCards.length > 0) {
                statusBar.setTitle(_('Select cards to play.'));
            } else {
                statusBar.setTitle(_('You have to pass.'));
            }
            this.enablePass();
            hand.setSelectionMode('single', playableCards);
            return;
        }

        if (st.suitDemand > 0) {
            ////////////////////// suit demand
            let playableCards = [];
            for (let i = 0; i < handCards.length; i++) {
                let card = handCards[i];
                if (
                    card.rank > 4
                    && card.rank < 11
                    && card.suit == st.suitDemand
                ) playableCards.push(card);
            }

            if (playCards.length > 0) {
                statusBar.setTitle('Click play when ready.');
                hand.setSelectionMode('single', playableCards);
                this.enablePlay();
                return;
            }

            if (playableCards.length > 0) {
                statusBar.setTitle(_('Select cards to play.'));
            } else {
                statusBar.setTitle(_('You have to pass.'));
            }
            this.enablePass();
            hand.setSelectionMode('single', playableCards);
            return;
        }

        let demand = '';
        let newdemand = '';
        for (let i = 0; i < playCards.length; i++) {
            let card = playCards[i];

            switch (card.rank) {
                case 2:
                case 3:
                    newdemand = 'draw';
                    break;
                case 4:
                    newdemand = 'skip';
                    break;
                case 11:
                    newdemand = 'rank';
                    break;
                case 13:
                    if (card.suit < 3) newdemand = 'draw';
                    break;
                case 14:
                    newdemand = 'suit';
                    break;
            }

            if (demand != '' && newdemand != demand) {
                statusBar.setTitle(_('You can\'t have multiple demands.'));
                return;
            }

            demand = newdemand;
        }

        let playableCards = [];
        let topCards = playCards;
        if (topCards.length == 0) topCards = this.discard.cards;
        let top = topCards[topCards.length - 1];

        for (let i = 0; i < handCards.length; i++) {
            let card = handCards[i];

            if (demand != '') {
                switch (card.rank) {
                    case 2:
                    case 3:
                        if (demand != 'draw') continue;
                        break;
                    case 4:
                        if (demand != 'skip') continue;
                    case 11:
                        if (demand != 'rank') continue;
                        break;
                    case 13:
                        if (card.suit < 3 && demand != 'draw') continue;
                        break;
                    case 14:
                        if (demand != 'suit') continue;
                        break;
                }
            }

            if (
                demand == 'draw' && (card.rank < 4 || card.rank == 13 && card.suit < 3) // playing multiple draws
                || demand == 'skip' && card.rank == 4 // playing multiple skips
                || card.rank == 14 // ace
                || card.rank == 12 // queen
                || card.suit == top.suit
                || card.rank == top.rank
                || top.rank == 12 // queen
            ) playableCards.push(card);
        }

        hand.setSelectionMode('single', playableCards);

        ////////////////////// no demands
        if (playCards.length > 0) {
            statusBar.setTitle('Click play when ready.');
            this.enablePlay();
            return;
        }

        if (playableCards.length < 1) {
            statusBar.setTitle(_('You have no playable cards.'));
        } else {
            statusBar.setTitle(_('Pick cards to play.'));
            if (!st.drew) this.enableDraw();
        }
        this.enablePass();
    }

    setupNotifications() {
        console.log('notifications subscriptions setup');

        this.bga.notifications.setupPromiseNotifications({
            // logger: console.log
        });
    }

    pickRank() {
        let statusBar = this.bga.statusBar;
        let st = this.st;

        statusBar.removeActionButtons();

        if (st.fwPlayerId == this.playerId) {
            let cards = this.hand.cards;

            statusBar.setTitle('Select a rank.');
            this.rankButtons = [];
            let availableRanks = [];

            for (let i = 0; i < cards.length; i++) {
                let card = cards[i];
                if (card.rank > 4 && card.rank < 11) {
                    availableRanks[card.rank] = card.rank;
                }
            }

            for (let i = 5; i < 11; i++) {
                if (availableRanks[i] == undefined) continue;
                this.rankButtons[i] = statusBar.addActionButton(_(`${i}`), () => {
                    this.bga.actions.performAction(
                        'actPickRank', {
                        rank: i
                    });
                });
            }

            this.rankButtons[11] = this.bga.statusBar.addActionButton(_('any'), () => {
                this.bga.actions.performAction(
                    'actPickRank', {
                    rank: 20,
                });
            });
        } else {
            statusBar.setTitle(`${this.bga.players.getPlayerById(st.fwPlayerId).name} is selecting a rank.`);
        }
    }

    pickSuit() {
        let statusBar = this.bga.statusBar;
        let st = this.st;
        statusBar.removeActionButtons();

        if (st.fwPlayerId == this.playerId) {
            let cards = this.hand.cards;

            statusBar.setTitle('Select a suit.');
            this.suitButtons = [];
            let availableSuits = [];

            for (let i = 0; i < cards.length; i++) {
                let card = cards[i];
                availableSuits[card.suit] = card.suit;
            }

            for (let i = 1; i < 5; i++) {
                if (availableSuits[i] == undefined) continue;
                // TODO: show emoji of the suit
                this.suitButtons[i] = statusBar.addActionButton(_(`${this.cardTypes["suitUnicode"][i]}`), () => {
                    this.bga.actions.performAction(
                        'actPickSuit', {
                        suit: i
                    });
                });
            }
            this.suitButtons[5] = statusBar.addActionButton(_('any'), () => {
                this.bga.actions.performAction(
                    'actPickSuit', {
                    suit: 20
                });
            });
        } else
            statusBar.setTitle(`${this.bga.players.getPlayerById(st.fwPlayerId).name} is selecting a suit.`);
    }

    stateUpdate(args) {
        for (var key in this.st) {
            if (args[key] != undefined) this.st[key] = args[key];
        }
    }

    async notif_Pass(args) {
        this.stateUpdate(args);

        this.st.suitDemand = 0;

        this.st[`skip${this.st.fwPlayerId}`] = args.skip;

        if (args.draw > 0) this.st[`draw${this.st.fwPlayerId}`] = args.draw;

        this.st.skip = 0;
        this.st.draw = 0;

        this.setPlayOptions();
    }

    async notif_DrawCards(args) {
        this.stateUpdate(args);

        this.deck.setCardNumber(args.deck);

        if (args._private) {
            await this.hand.addCards(Array.from(Object.values(args._private.cards)));
            this.st.drew = 1;
        } else
            this.updateHandSize(args.playerId, args[`hand${args.playerId}`]);

        this.setPlayOptions();
    }

    async notif_PlayCards(args) {
        this.stateUpdate(args);

        this.discard.addCards(Array.from(Object.values(args.cards)));
        this.updateHandSize(args.playerId, args[`hand${args.playerId}`]);

        this.setPlayOptions();
    }

    async notif_Selected(args) {
        this.stateUpdate(args);

        this.setPlayOptions();
    }

    async notif_CalledMakau(args) {
        this.stateUpdate(args);

        if (args._private) {
            this.hand.addCards(Array.from(Object.values(args._private.cards)));
        } else {
            this.updateHandSize(args.onPlayerId, args[`hand${args.onPlayerId}`]);
        }

        this.setPlayOptions();
    }

    async notif_InvalidPlay(args) {
        this.hand.unselectAll();

        this.setPlayOptions();
    }
}