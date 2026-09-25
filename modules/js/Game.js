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

        this.card_types = {
            "suit": {
                1: "Spade",
                2: "Heart",
                3: "Club",
                4: "Diamond",
            },
            "suit_unicode": {
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
        let r = `${this.card_types["rank"][card.rank]} ${this.card_types["suit_unicode"][card.suit]}`;
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
        this.playerId = args.playerId;
        this.fwPlayerId = args.fwPlayerId;
        this.bwPlayerId = args.bwPlayerId;
        this.playerIds = args.playerIds;
        this.skip = args.skip;
        this.draw = args.draw;
        this.rankPick = args.rankpick;
        this.drawpick = args.drawpick;
        this.rankDemand = args.rankDemand;
        this.lastJack = args.lastJack;
        this.drew = args.drew;

        for (let i = 0; i < this.playerIds.length; i++) {
            let playerId = this.playerIds[i];
            this[`draw${this.playerId}`] = args[`draw${playerId}`];
            this[`skip${this.playerId}`] = args[`skip${playerId}`];
        }

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
            if (this.skip > 0 && card.rank == 4)
                playable = true;

            if (this.draw > 0 &&
                (
                    card.rank == 2
                    || card.rank == 3
                    || card.rank == 13 // TODO: king
                )
            )
                playable = true;

            if (this.rankDemand > 0
                && (
                    card.rank == this.rankDemand
                    || card.rank === 11
                )
            )
                playable = true;

            if (this.skip == 0
                && this.draw == 0
                && this.rankDemand == 0
                && (
                    card.rank == 12 // queen
                    || card.suit == top.suit
                    || card.rank == top.rank
                    || top.rank == 12 // queen
                )
            )
                playable = true;

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

        if (this.rankPick)
            return this.pickRank();

        if (this.suitPick)
            return this.pickSuit();

        let handCards = this.hand.getCards();
        let topCards = this.play.getCards();
        if (topCards.length == 0) topCards = this.discard.getCards();
        let top = topCards[topCards.length - 1];

        let skip = this[`skip${currentPlayerId}`];
        let draw = this[`draw${currentPlayerId}`];

        if (this.fwPlayerId == this.lastJack) {
            this.lastJack = 0; this.rankDemand = 0;
        }

        let playableCards = this.getPlayableCards(top, handCards);

        if (currentPlayerId == this.fwPlayerId) {
            if (this[`skip${this.fwPlayerId}`] > 0) {
                this.bga.statusBar.setTitle(_('You must skip your turn.'));
                this.buttonsPass();
                playableCards = [];
            }

            if (this[`draw${this.fwPlayerId}`] > 0 || this.draw && playableCards.length < 1) {
                this.bga.statusBar.setTitle(_('You must draw cards.'));
                this.buttonsDraw();
                playableCards = [];
            } else {
                if (this.play.getCards().length < 1) {
                    if (playableCards.length < 1) {
                        this.bga.statusBar.setTitle(_('You have no playable cards.'));
                        if (!this.drew) this.buttonsDraw();
                        this.buttonsPass();
                    } else {
                        this.bga.statusBar.setTitle(_('Pick cards to play.'));
                        this.buttonsPlay();
                        if (!this.drew) this.buttonsDraw();
                        this.buttonsPass();

                    }
                } else {
                    this.bga.statusBar.setTitle(_('Click play when you\'re done...'));
                    this.buttonsPlay();
                    if (!this.drew) this.buttonsDraw();
                    this.buttonsPass();
                }
            }
        } else if (currentPlayerId == this.bwPlayerId) {
            // TODO: bw_active_player
        } else {
            this.draw = 0;
            this.skip = 0;
            playableCards = [];

            this.buttonsMakau();
            this.bga.statusBar.setTitle(_("${playerName} is playing now."), {
                "playerName": this.bga.players.getPlayerById(this.fwPlayerId).name,
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

        if (this.fwPlayerId == this.playerId) {
            let cards = this.hand.cards;

            this.bga.statusBar.setTitle('Select a rank.');
            this.rankButtons = [];
            let availableRanks = [];
            availableRanks['any'] = 15; // TODO: get to work!
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
                        'actPick', {
                        rank: i
                    });
                });
            }
        } else {
            this.bga.statusBar.setTitle(`${this.bga.players.getPlayerById(this.fwPlayerId).name} is selecting a rank.`);
        }
    }

    pickSuit() {
        debugger;
        this.bga.statusBar.removeActionButtons();

        if (this.fwPlayerId == this.playerId) {
            let cards = this.hand.cards;

            this.bga.statusBar.setTitle('Select a suit.');
            this.suitButtons = [];
            let availableSuits = [];
            availableSuits['any'] = 15; // TODO: get to work!
            for (let i = 0; i < cards.length; i++) {
                let card = cards[i];
                availableSuits[card.suit] = card.suit;
            }

            for (let i = 1; i < 5; i++) {
                if (availableSuits[i] == undefined) continue;
                // TODO: show emoji of the suit
                this.suitButtons[i] = this.bga.statusBar.addActionButton(_(`${this.cardTypes["suitUnicode"][i]}`), () => {
                    this.bga.actions.performAction(
                        'actPick', {
                        suit: i
                    });
                });
            }
        } else {
            this.bga.statusBar.setTitle(`${this.bga.players.getPlayerById(this.fwPlayerId).name} is selecting a suit.`);
        }
    }

    async notif_Pass(args) {
        debugger;
        this.suitDemand = 0;
        this[`skip${args.fwPlayerId}`] = args.skip;
        this.fwPlayerId = args.fwPlayerId;
        this.setPlayOptions();
    }

    async notif_DrawCards(args) {
        debugger;
        this.deck.setCardNumber(args.deck);
        if (args._private) {
            await this.hand.addCards(Array.from(Object.values(args._private.cards)));
            this.drew = 1;
        }
        this.draw = 0;
        this.setPlayOptions();
    }

    async notif_PlayCards(args) {
        debugger;
        this.discard.addCards(Array.from(Object.values(args.cards)));
        this.updateHandSize(args.playerId, args.hand_size);

        this.draw = args.draw;
        this.skip = args.skip;
        this.suitDemand = args.suitDemand;
        this.rankDemand = args.rankDemand;
        this.lastJack = args.lastJack;
        // note: the player id's might not change (such as when picking rank/suit)
        this.bwPlayerId = args.bwPlayerId;
        this.fwPlayerId = args.fwPlayerId;
        this.rankPick = args.rankPick;
        this.suitPick = args.suitPick;
        this.drew = 0;

        this.setPlayOptions();
    }

    async notif_RankDemand(args) {
        debugger;
        this.rankDemand = args.rankDemand;
        this.fwPlayerId = args.fwPlayerId;
        this.pickRank = 0;
        this.lastJack = args.lastJack;
    }

    async notif_SuitDemand(args) {
        debugger;
        this.suitDemand = args.suitDemand;
        this.pickSuit = 0;
        this.fwPlayerId = args.fwPlayerId;
    }

    async notif_InvalidPlay(args) {
        this.hand.unselectAll();
    }
}