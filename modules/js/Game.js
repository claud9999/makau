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
            case 11:
                r += ' (demand non-action rank of other players)';
                break;
            case 12:
                r += ' (anything on queen, queen on anything)';
                break;
            case 14:
                r += ' (demand next player play suit)';
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

        this.bga.gameArea.getElement().insertAdjacentHTML(
            "beforeend",
            `
                    <div id="table" class="table" height="200px">
                        <div id="otherstuff">
                            <div id="deck_block" bgcolor="green">
                                <b id="deck_label">${_("Deck")}</b>
                                <div id="deck"></div>
                            </div>
                            <div id="discard_block" bgcolor="yellow">
                                <b align="left" id="discard_label">${_("Discard pile")}</b>
                                <div id="discard"></div>
                            </div>
                            <div id="play_block">
                                <b id="play_label"></b>
                                <div id="play"></div>
                            </div>
                        </div>
                        <div id="otherplayers" class="player_blocks">
            `
        );

        for (let i = 0; i < args.playerIds.length; i++) {
            let playerId = args.playerIds[i];

            if (playerId == this.bga.players.getCurrentPlayerId()) continue;

            this.bga.gameArea.getElement().insertAdjacentHTML(
                "beforeend",
                `
                        <div id="hand${playerId}" class="player_block">
                            <b id="hand${playerId}_label">${this.bga.players.getPlayerById(playerId).name}'s hand</b>
                        </div>
                `
            );
        }

        // close the "otherplayers" and "table" tags
        this.bga.gameArea.getElement().insertAdjacentHTML(
            "beforeend",
            `
                    </div><!-- close otherplayers -->
                    <div id="hand_block">
                        <b id="hand_label">${_("My hand")}</b>
                        <div id="hand"></div>
                    </div>
                </div><!-- close table -->
            `
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
            }
        });

        for (let i = 0; i < args.playerIds.length; i++) {
            let playerId = args.playerIds[i];
            if (playerId == this.bga.players.getCurrentPlayerId()) continue;

            this[`hand${playerId}`] = new BgaCards.HandStock(
                this.cardsManager,
                document.getElementById(`hand${playerId}`),
                {
                    cardOverlap: 75
                }
            );
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

        // Setup game notifications to handle (see "setupNotifications" method below)
        this.setupNotifications();

        console.log("Ending game setup");
        this.setPlayOptions();
    }

    buttonsPlay() {
        this.playButton = this.bga.statusBar.addActionButton(_('Play'), () => {
            this.bga.actions.performAction(
                'actPlay', {
                cardIds: this.play.getCards().map((card) => card.id)
            });
        });
    }

    buttonsDraw() {
        this.drawButton = this.bga.statusBar.addActionButton(_('Draw'), () => {
            this.hand.addCards(this.play.getCards());
            this.bga.actions.performAction('actDraw');
        });
    }

    buttonsPass() {
        this.passButton = this.bga.statusBar.addActionButton(_('Pass'), () => {
            this.hand.addCards(this.play.getCards());
            this.bga.actions.performAction('actPass');
        });
    }

    buttonsMakau() {
        this.makauButton = this.bga.statusBar.addActionButton(_('Makau'), () => this.bga.actions.performAction('actMakau'));
    }

    // Should match getPlayableCards in States/GameOn.php
    getPlayableCards(top, cards) {
        let matchingCards = [];

        for (let i = 0; i < cards.length; i++) {
            let card = cards[i];
            let playable = false;
            if (this.st.skip > 0 && card.rank == 4)
                playable = true;

            if (this.st.draw > 0 &&
                (
                    card.rank == 2
                    || card.rank == 3
                    || card.rank == 13 // TODO: king
                )
            ) playable = true;

            if (this.st.rankDemand > 0
                && this.st.rankDemand < 20
                && (
                    card.rank == this.st.rankDemand
                    || card.rank === 11
                )
            ) playable = true;

            if (this.st.suitDemand > 0
                && this.st.suitDemand < 20
                && card.suit == this.st.suitDemand
                && card.rank > 4
                && card.rank < 11
            ) playable = true;

            if (
                (this.st.rankDemand == 20 || this.st.suitDemand == 20)
                && card.rank > 4
                && card.rank < 11
            ) playable = true;

            if (this.st.skip == 0
                && this.st.draw == 0
                && this.st.rankDemand == 0
                && this.st.suitDemand == 0
                && (
                    card.rank == 12 // queen
                    || card.suit == top.suit
                    || card.rank == top.rank
                    || top.rank == 12 // queen
                )
            ) playable = true;

            if (playable)
                matchingCards.push(card);
        }

        return matchingCards;
    }

    updateHandSize(playerId, count) {
        if (this[`hand${playerId}`] == undefined) return;

        this[`hand${playerId}`].removeAll();

        for (let j = 0; j < count; j++) {
            this[`hand${playerId}`].addCard({
                id: `player${playerId}card${j}`,
                suit: 0,
                rank: 0
            });
        }
    }

    setPlayOptions() {
        let currentPlayerId = this.bga.players.getCurrentPlayerId();
        this.bga.statusBar.removeActionButtons();

        if (this.st.rankPick > 0)
            return this.pickRank();

        if (this.st.suitPick > 0)
            return this.pickSuit();

        let handCards = this.hand.getCards();
        let topCards = this.play.getCards();
        if (topCards.length == 0) topCards = this.discard.getCards();
        let top = topCards[topCards.length - 1];

        let skip = this.st[`skip${currentPlayerId}`];
        let draw = this.st[`draw${currentPlayerId}`];

        if (this.st.fwPlayerId == this.st.lastJack) {
            this.st.lastJack = 0; this.st.rankDemand = 0;
        }

        let playableCards = this.getPlayableCards(top, handCards);

        if (currentPlayerId == this.st.fwPlayerId) {
            if (this[`skip${this.st.fwPlayerId}`] > 0) {
                this.bga.statusBar.setTitle(_('You must skip your turn.'));
                this.buttonsPass();
                playableCards = [];
            } else if (this.st[`draw${this.st.fwPlayerId}`] > 0) {
                this.bga.statusBar.setTitle(_('You must draw cards.'));
                this.buttonsDraw();
                playableCards = [];
            } else {
                if (this.play.getCards().length < 1) {
                    if (playableCards.length < 1) {
                        if (this.st.draw > 0) {
                            this.bga.statusBar.setTitle(_('You must draw cards.'));
                            this.buttonsDraw();
                            playableCards = [];
                        } else {
                            this.bga.statusBar.setTitle(_('You have no playable cards.'));
                            if (!this.st.drew) this.buttonsDraw();
                            this.buttonsPass();
                        }
                    } else {
                        this.bga.statusBar.setTitle(_('Pick cards to play.'));
                        if (!this.st.drew) this.buttonsDraw();
                        this.buttonsPass();

                    }
                } else {
                    this.bga.statusBar.setTitle(_('Click play when you\'re done...'));
                    this.buttonsPlay();
                    if (!this.st.drew) this.buttonsDraw();
                    this.buttonsPass();
                }
            }
        } else if (currentPlayerId == this.st.bwPlayerId) {
            // TODO: bw_active_player
        } else {
            this.st.draw = 0;
            this.st.skip = 0;
            playableCards = [];

            this.buttonsMakau();
            this.bga.statusBar.setTitle(_("${playerName} is playing now."), {
                "playerName": this.bga.players.getPlayerById(this.st.fwPlayerId).name,
            });
        }

        this.hand.setSelectionMode('single', playableCards);
    }

    setupNotifications() {
        console.log('notifications subscriptions setup');

        this.bga.notifications.setupPromiseNotifications({
            // logger: console.log
        });
    }

    pickRank() {
        this.bga.statusBar.removeActionButtons();

        if (this.st.fwPlayerId == this.playerId) {
            let cards = this.hand.cards;

            this.bga.statusBar.setTitle('Select a rank.');
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
                this.rankButtons[i] = this.bga.statusBar.addActionButton(_(`${i}`), () => {
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
            this.bga.statusBar.setTitle(`${this.bga.players.getPlayerById(this.st.fwPlayerId).name} is selecting a rank.`);
        }
    }

    pickSuit() {
        this.bga.statusBar.removeActionButtons();

        if (this.st.fwPlayerId == this.playerId) {
            let cards = this.hand.cards;

            this.bga.statusBar.setTitle('Select a suit.');
            this.suitButtons = [];
            let availableSuits = [];

            for (let i = 0; i < cards.length; i++) {
                let card = cards[i];
                availableSuits[card.suit] = card.suit;
            }

            for (let i = 1; i < 5; i++) {
                if (availableSuits[i] == undefined) continue;
                // TODO: show emoji of the suit
                this.suitButtons[i] = this.bga.statusBar.addActionButton(_(`${this.cardTypes["suitUnicode"][i]}`), () => {
                    this.bga.actions.performAction(
                        'actPickSuit', {
                        suit: i
                    });
                });
            }
            this.suitButtons[5] = this.bga.statusBar.addActionButton(_('any'), () => {
                this.bga.actions.performAction(
                    'actPickSuit', {
                    suit: 20
                });
            });
        } else
            this.bga.statusBar.setTitle(`${this.bga.players.getPlayerById(this.st.fwPlayerId).name} is selecting a suit.`);
    }

    async notif_Pass(args) {
        this.st.suitDemand = 0;
        this.st[`skip${this.st.fwPlayerId}`] = args.skip;
        if (args.draw > 0) this.st[`draw${this.st.fwPlayerId}`] = args.draw;
        this.st.skip = 0;
        this.st.draw = 0;
        this.st.fwPlayerId = args.fwPlayerId;
        this.setPlayOptions();
    }

    async notif_DrawCards(args) {
        this.deck.setCardNumber(args.deck);
        if (args._private) {
            await this.hand.addCards(Array.from(Object.values(args._private.cards)));
            this.st.drew = 1;
        }
        this.st.draw = 0;
        this.setPlayOptions();
    }

    async notif_PlayCards(args) {
        this.discard.addCards(Array.from(Object.values(args.cards)));
        this.updateHandSize(args.playerId, args.handSize);

        this.st.draw = args.draw;
        this.st.skip = args.skip;
        this.st.suitDemand = args.suitDemand;
        this.st.rankDemand = args.rankDemand;
        this.st.lastJack = args.lastJack;
        // note: the player id's might not change (such as when picking rank/suit)
        this.st.bwPlayerId = args.bwPlayerId;
        this.st.fwPlayerId = args.fwPlayerId;
        this.st.rankPick = args.rankPick;
        this.st.suitPick = args.suitPick;
        this.st.drew = 0;

        this.setPlayOptions();
    }

    async notif_RankDemand(args) {
        this.st.rankDemand = args.rankDemand;
        this.st.fwPlayerId = args.fwPlayerId;
        this.st.rankPick = 0;
        this.st.lastJack = args.lastJack;

        this.setPlayOptions();
    }

    async notif_SuitDemand(args) {
        this.st.suitDemand = args.suitDemand;
        this.st.fwPlayerId = args.fwPlayerId;
        this.st.suitPick = 0;

        this.setPlayOptions();
    }

    async notif_InvalidPlay(args) {
        this.hand.unselectAll();

        this.setPlayOptions();
    }
}