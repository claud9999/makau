const BgaAnimations = await importEsmLib('bga-animations', '1.x');
const BgaCards = await importEsmLib('bga-cards', '1.x');

class GameOn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(_args, isCurrentPlayerActive) {
        this.game.setPlayOptions();
    }
}

class NewHand {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }
}

class NextPlayer {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }
}

class PickRank {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }
}

export class Game {
    constructor(bga) {
        console.log('makaucloudnein constructor');
        this.bga = bga;

        this.GameOn = new GameOn(this, bga);
        this.bga.states.register('NewHand', new NewHand(this, bga));
        this.bga.states.register('GameOn', new GameOn(this, bga));
        this.bga.states.register('PickRank', new PickRank(this, bga));

        this.bga.states.logger = console.log;
        this.drew = 0;
        this.card_types = {
            "suit": {
                1: "Spade",
                2: "Heart",
                3: "Club",
                4: "Diamond",
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
                14: "Joker"
            },
        }
    }

    setup(args) {
        console.log("Starting game setup");
        this.active_player_no = args.active_player_no;
        this.player_ids = args.player_ids;
        this.skip = args.skip;
        this.draw = args.draw;
        this.drew = args.drew;

        if (args[`skip_${this.bga.players.getCurrentPlayerId()}`] > 0) {
            this.skip_prev = args[`skip_${this.bga.players.getCurrentPlayerId()}`];
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

        for (let i = 0; i < args.player_ids.length; i++) {
            let player_id = args.player_ids[i];

            if (player_id == this.bga.players.getCurrentPlayerId()) continue;

            this.bga.gameArea.getElement().insertAdjacentHTML(
                "beforeend",
                `
                        <div id="hand_${player_id}" class="player_block">
                            <b id="hand_${player_id}_label">${this.bga.players.getPlayerById(player_id).name}'s hand</b>
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
                this.bga.gameui.addTooltipHtml(div.id, `${this.card_types["rank"][card.rank]} of ${this.card_types["suit"][card.suit]}s`);
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

        for (let i = 0; i < args.player_ids.length; i++) {
            let player_id = args.player_ids[i];
            if (player_id == this.bga.players.getCurrentPlayerId()) continue;

            this[`hand_${player_id}`] = new BgaCards.HandStock(
                this.cardsManager,
                document.getElementById(`hand_${player_id}`),
                {
                    cardOverlap: 75
                }
            );

            this.updateOtherPlayerHandCount(args);
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
    }

    buttonsMakau() {
        this.makauButton = this.bga.statusBar.addActionButton(_('Makau'), () => this.bga.actions.performAction('actMakau'));
    }

    buttonsGameOn() {
        this.playButton = this.bga.statusBar.addActionButton(_('Play'), () => {
            this.bga.actions.performAction(
                'actPlay', {
                cardIds: this.play.getCards().map((card) => card.id)
            });
        });
        this.drawButton = this.bga.statusBar.addActionButton(_('Draw'), () => {
            this.hand.addCards(this.game.play.getCards());
            this.bga.actions.performAction('actDraw');
        });
        this.passButton = this.bga.statusBar.addActionButton(_('Pass'), () => {
            this.hand.addCards(this.game.play.getCards());
            this.bga.actions.performAction('actPass');
        });
        this.buttonsMakau();
    }

    // Should match getPlayableCards in States/GameOn.php
    getPlayableCards(top, cards) {
        let matchingCards = [];

        for (let i = 0; i < cards.length; i++) {
            let card = cards[i];
            if (this.skip > 0) {
                if (card.rank == 4)
                    matchingCards.push(card);
            } else if (this.draw > 0) {
                if (card.rank == 2
                    || card.rank == 3
                    || card.rank == 13 // king
                )
                    matchingCards.push(card);
            } else {
                if (card.rank == 12 // queen
                    || card.suit == top.suit
                    || card.rank == top.rank
                    || top.rank == 12 // queen
                )
                    matchingCards.push(card);
            }
        }

        return matchingCards;
    }

    setPlayOptions() {
        this.bga.statusBar.removeActionButtons();

        let handCards = this.hand.getCards();
        let topcards = this.play.getCards();
        if (topcards.length == 0) topcards = this.discard.getCards();
        let top = topcards[topcards.length - 1];

        let selectableCards = [];

        if (this.bga.players.getCurrentPlayerNo() == this.active_player_no) {
            this.buttonsGameOn();
            if (this.skip_prev > 0) {
                this.bga.statusBar.setTitle(_('You must skip your turn.'));
            } else if (this.skip > 0) {
                selectableCards = this.getPlayableCards(top, handCards);

                if (selectableCards.length < 1) {
                    this.bga.statusBar.setTitle(_('You must skip your turn.'));
                } else {
                    this.bga.statusBar.setTitle(_('You can play a skip card.'));

                    this.playButton.disabled = false;
                    this.drawButton.disabled = true;
                }
            } else if (this.draw > 0) {
                selectableCards = this.getPlayableCards(top, handCards);

                if (selectableCards.length < 1) {
                    this.bga.statusBar.setTitle(_(`You must draw ${this.draw} cards.`));
                    this.drawButton.disabled = false;
                    this.passButton.disabled = true;
                } else {
                    this.bga.statusBar.setTitle(_('You can add to the draw.'));

                    this.playButton.disabled = false;
                    this.drawButton.disabled = true;
                    this.passButton.disabled = true;
                }
            } else {
                this.drawButton.disabled = this.drew;
                selectableCards = this.getPlayableCards(top, handCards);

                if (this.play.getCards().length < 1) {
                    if (selectableCards.length < 1)
                        this.bga.statusBar.setTitle(_('You have no playable cards.'));
                    else
                        this.bga.statusBar.setTitle(_('Pick cards to play.'));
                } else {
                    this.bga.statusBar.setTitle(_('Click play when you\'re done...'));

                    this.playButton.disabled = false;
                }
            }
        } else {
            this.buttonsMakau();
            this.bga.statusBar.setTitle(_("${active_player_no} is playing now."), {
                "active_player_no": this.bga.players.getPlayerByNo(this.active_player_no).name,
            });
        }
        this.hand.setSelectionMode('single', selectableCards);
    }

    setupNotifications() {
        console.log('notifications subscriptions setup');

        this.bga.notifications.setupPromiseNotifications({
            // logger: console.log
        });
    }

    updateOtherPlayerHandCount(args) {
        for (let i = 0; i < this.player_ids.length; i++) {
            let player_id = this.player_ids[i];
            if (args[`hand_${player_id}`] == undefined || this[`hand_${player_id}`] == undefined) continue;

            this[`hand_${player_id}`].removeAll();

            for (let j = 0; j < args[`hand_${player_id}`]; j++) {
                this[`hand_${player_id}`].addCard({
                    id: `player_${player_id}_card_${j}`,
                    suit: 0,
                    rank: 0
                });
            }
        }
    }

    async notif_newHand(args) {
        // We received a new full hand of cards.
        await this.hand.removeAll();
        await this.hand.addCards(Array.from(Object.values(args.hand)));
        await this.discard.removeAll();
        await this.discard.addCards(Array.from(Object.values(args.discards)));
        this.active_player_no = args.active_player_no;
        this.skip = 0;
        this.draw = 0;
        this.drew = 0;
        this.deck.setCardNumber(args.deck);

        this.updateOtherPlayerHandCount(args);
        this.setPlayOptions();
    }

    async notif_Deal(args) {
        if (args._private) {
            await this.hand.addCards(args._private.cards);
        }
        this.drew = 0;
        this.draw = 0;
        this.deck.setCardNumber(args.deck);

        this.updateOtherPlayerHandCount(args);
        this.setPlayOptions();
    }

    async notif_Pass(args) {
        if (this.bga.players.getCurrentPlayerNo() == this.active_player_no) {
            let skip = this.skip + this[`skip_${args.player_id}`];

            if (skip > 0) {
                this[`skip_${args.player_id}`] = skip - 1;
            }
        }
        this.skip = 0;
    }

    async notif_DrawCards(args) {
        this.deck.setCardNumber(args.deck);
        if (args._private) {
            this.hand.addCards(Array.from(Object.values(args._private.cards)));
            this.drew = 1;
            this.setPlayOptions();
        } else {
            this.updateOtherPlayerHandCount(args);
        }
        this.draw = 0;
    }

    async notif_NextPlayer(args) {
        this.active_player_no = args.active_player_no;
        this.drew = 0;
        this.setPlayOptions();
    }

    async notif_PlayCards(args) {
        this.discard.addCards(Array.from(Object.values(args.cards)));

        this.draw = args.draw;
        this.skip = args.skip;
        this.suit_demand = args.suit_demand;
        this.rank_demand = args.rank_demand;
        this.last_jack = args.last_jack;

        this.updateOtherPlayerHandCount(args);
    }

    async notif_PickRank(args) {
        this.bga.statusBar.removeActionButtons();
        this.bga.statusBar.setTitle('Select a rank.');
        this.rankButtons = [];
        for (let i = 5; i < 11; i++) {
            this.rankButtons[i] = this.bga.statusBar.addActionButton(_(`{i}`), () => {
                this.bga.actions.performAction(
                    'actPick', {
                    rank: i
                });
            });
        }
    }

    async notif_RankDemand(args) {
        this.bga.statusBar.removeActionButtons();
        this.rank_demand = args.rank;
        this.last_jack = $player_id;
    }

    async notif_InvalidPlay(args) {
        this.hand.unselectAll();
    }
}