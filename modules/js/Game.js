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

// definitely needs to be the same as defined in Game.php
const NO_DEMAND = 0, DRAW_DEMAND = 1, SKIP_DEMAND = 2, RANK_DEMAND = 3, SUIT_DEMAND = 4;

const SPADE = 1, HEART = 2, CLUB = 3, DIAMOND = 4, ANY_SUIT = 20;

const ACE = 1, JACK = 11, QUEEN = 12, KING = 13, JOKER = 14, ANY_RANK = 20;

export class Game {
    constructor(bga) {
        this.bga = bga;

        this.bga.states.register('GameOn', new GameOn(this, bga));

        this.bga.states.logger = console.log;

        this.cardTypes = {
            "suit": { // TODO: use consts
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
                1: "Ace",
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

        this.jokers = {};
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
            case JACK:
                r += ' (demand non-action rank of other players)';
                break;
            case QUEEN:
                r += ' (anything on Q, Q on anything)';
                break;
            case KING:
                switch (card.suit) {
                    case SPADE:
                        r += ' (previous player draws 5)';
                        break;
                    case HEART:
                        r += ' (next player draws 5)';
                        break;
                    case CLUB:
                        r += ' (blocks K)';
                        break;
                    case DIAMOND:
                        r += ' (blocks K)';
                        break;
                }
                break;
            case ACE:
                r += ' (wild, demand next player play suit)';
                break;
            case JOKER:
                r = 'Joker (can be played as any card at any time)';
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
                div.dataset.rank = card.rank; // value 1..14

                let cardfn = `${card.rank}-${card.suit}.png`;
                if (card.rank == JOKER) cardfn = 'joker.png';
                div.style.backgroundImage = `url('${this.bga.images.getImgUrl(cardfn)}')`;

                this.bga.gameui.addTooltipHtml(div.id, this.tooltip(card));
            },
            setupBackDiv: (card, div) => {
                div.style.backgroundImage = `url('${this.bga.images.getImgUrl('back.png')}')`
            },
            isCardVisible: (card) => { return card.rank > 0; }
        });

        this.deck = new BgaCards.Deck(this.cardsManager, document.getElementById('deck'), {
            cardNumber: args.deck,
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

            let handName = `hand${playerId}`;
            this[handName] = new BgaCards.DiscardDeck(
                this.cardsManager,
                document.getElementById(handName),
                {
                    cardOverlap: 75
                }
            );
            this.updateHandSize(playerId, args[handName]);
        }

        this.hand = new BgaCards.HandStock(
            this.cardsManager,
            document.getElementById("hand"),
            {
                cardOverlap: 75,
            }
        );
        this.hand.addCards(args.hand);

        this.play = new BgaCards.HandStock(
            this.cardsManager,
            document.getElementById("play"),
            {
                fanShaped: false,
                cardOverlap: 90,
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

        this.discard.addCards(args.discard);

        this.hand.onCardClick = (card) => {
            if (args.gamestate.name != "GameOn") this.hand.unselectAll();
            if (card.rank == JOKER)
                this.jokerRank(card);
            else {
                this.play.addCard(card);
                this.setPlayOptions();
            }
        }

        this.play.onCardClick = (card) => {
            if (this.jokers[card.id]) {
                card.rank = JOKER;
                card.suit = 0;
            }

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
                cardIds: this.play.getCards().map((card) => card.id),
                jokers: JSON.stringify(this.jokers),
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

    jokerRank(card) {
        // Handle joker rank logic
        let statusBar = this.bga.statusBar;
        let st = this.st;
        let allowed = [ACE, 2, 3, 4, 5, 6, 7, 8, 9, 10, JACK, QUEEN, KING];

        statusBar.removeActionButtons();

        switch (st.demand) {
            case SKIP_DEMAND:
                allowed = [4];
                break;
            case DRAW_DEMAND:
                allowed = [2, 3, KING];
                break;
            case RANK_DEMAND:
                allowed = [st.demandArg, JACK];
                break;
            case SUIT_DEMAND:
                allowed = [5, 6, 7, 8, 9, 10];
                break;
        }

        statusBar.setTitle('Select a rank.');
        this.rankButtons = [];
        for (let i = 0; i < allowed.length; i++) {
            this.rankButtons[i] = statusBar.addActionButton(_(`${this.cardTypes.rank[allowed[i]]}`), () => {
                this.jokerSuit(card, allowed[i]);
            });
        }
    }

    jokerSuit(card, rank) {
        // Handle joker suit selection logic
        let statusBar = this.bga.statusBar;
        let allowed = [SPADE, HEART, CLUB, DIAMOND];
        let st = this.st;

        statusBar.removeActionButtons();

        if (st.demand == SUIT_DEMAND) allowed = [st.demandArg];

        statusBar.setTitle('Select a suit.');
        this.suitButtons = [];
        for (let i = 0; i < allowed.length; i++) {
            this.suitButtons[i] = statusBar.addActionButton(_(`${this.cardTypes.suitUnicode[allowed[i]]}`), () => {
                this.jokerSet(card, rank, allowed[i]);
            });
        }
    }

    jokerSet(card, rank, suit) {
        // Handle joker set logic
        let statusBar = this.bga.statusBar;

        statusBar.removeActionButtons();
        this.jokers[card.id] = { rank: rank, suit: suit };
        card.rank = rank;
        card.suit = suit;

        this.play.addCard(card);
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
        let hand = this[`hand${playerId}`];
        if (hand == undefined) return;
        if (hand.cards.length == count) return;

        let toAdd = count - hand.cards.length;

        if (toAdd < 0) {
            toAdd = count;
            hand.removeAll();
        }

        for (let j = 0; j < toAdd; j++) {
            hand.addCard({
                id: `player${playerId}card${j}`,
                suit: 0,
                rank: 0
            });
        }
    }

    callMakau(activePlayerId, onPlayerId) {
        let st = this.st;

        if (
            this.bga.players.isCurrentPlayerSpectator()
            || st[`makau{onPlayerId}`] > 0
        ) return;

        if (activePlayerId == onPlayerId) {
            if (this.hand.cards.length != 1) return;
        } else {
            if (this[`hand${onPlayerId}`].cards.length != 1) return;
        }

        return this.bga.actions.performAction('actMakau',
            {
                'onPlayerId': onPlayerId,
            }, {
            checkAction: false,
            checkPossibleAction: true,
        }
        );
    }

    setPlayOptions() {
        // conveniences
        let statusBar = this.bga.statusBar;
        let hand = this.hand;
        let handCards = this.hand.cards;
        let playCards = this.play.cards;
        let st = this.st;
        let players = this.bga.players;

        this.disableDraw();
        this.disablePlay();
        this.disablePass();

        let currentPlayerId = this.bga.players.getCurrentPlayerId();
        let activePlayerId = this.bga.players.getActivePlayerId();

        statusBar.removeActionButtons();

        let playableCards = [];

        if (activePlayerId != currentPlayerId) {
            statusBar.setTitle(_("${playerName} is playing now."), {
                "playerName": players.getPlayerById(activePlayerId).name,
            });

            hand.setSelectionMode('single', []);
            return;
        }

        // handle skips first, 'cause if there's also other demands,
        // they don't apply to the skipped player
        if (st[`skip${currentPlayerId}`] > 0) {
            ////////////////////// must skip
            statusBar.setTitle(_('You must pass.'));
            this.enablePass();
            hand.setSelectionMode('single', []);
            return;
        }

        if (st.rankPick) return this.pickRank();
        if (st.suitPick) return this.pickSuit();

        switch (st.demand) {
            case SKIP_DEMAND:
                for (let i = 0; i < handCards.length; i++) {
                    let card = handCards[i];
                    if (card.rank == 4 || card.rank == JOKER) playableCards.push(card);
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
            case DRAW_DEMAND:
                for (let i = 0; i < handCards.length; i++) {
                    let card = handCards[i];
                    if (
                        card.rank == 2
                        || card.rank == 3
                        || card.rank == KING &&
                        (
                            st.battleKing > 0
                            || card.rank == SPADE
                            || card.rank == HEART
                        )
                        || card.rank == JOKER
                    ) playableCards.push(card);
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
            case RANK_DEMAND:
                if (playCards.length > 0) {
                    statusBar.setTitle('Click play when ready.');
                    hand.setSelectionMode('single', playableCards);
                    this.enablePlay();
                    return;
                }

                for (let i = 0; i < handCards.length; i++) {
                    let card = handCards[i];
                    if (
                        card.rank == st.demandArg
                        || st.demandArg == ANY_RANK && card.rank > 4 && card.rank < 11
                        || card.rank == JACK
                        || card.rank == JOKER
                    ) playableCards.push(card);
                }

                if (playableCards.length > 0) {
                    statusBar.setTitle(_('Select cards to play.'));
                } else {
                    statusBar.setTitle(_('You have to pass.'));
                }
                this.enablePass();
                hand.setSelectionMode('single', playableCards);
                return;
            case SUIT_DEMAND:
                for (let i = 0; i < handCards.length; i++) {
                    let card = handCards[i];
                    if (
                        card.rank > 4 && card.rank < 11
                        && card.suit == st.demandArg
                        || card.rank == JOKER
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

        ////////////////////// no demands
        let demand = NO_DEMAND;
        let newdemand = NO_DEMAND;
        for (let i = 0; i < playCards.length; i++) {
            let card = playCards[i];

            switch (card.rank) {
                case 2:
                case 3:
                    newdemand = DRAW_DEMAND;
                    break;
                case 4:
                    newdemand = SKIP_DEMAND;
                    break;
                case 11:
                    newdemand = RANK_DEMAND;
                    break;
                case 13:
                    if (card.suit < 3) newdemand = DRAW_DEMAND;
                    break;
                case 1:
                    newdemand = SUIT_DEMAND;
                    break;
            }

            if (demand != NO_DEMAND && newdemand != demand) {
                statusBar.setTitle(_('You can\'t have multiple demands.'));
                return;
            }

            demand = newdemand;
        }

        let topCards = playCards;
        if (topCards.length == 0) topCards = this.discard.cards;
        let top = topCards[topCards.length - 1];

        for (let i = 0; i < handCards.length; i++) {
            let card = handCards[i];

            if (demand != NO_DEMAND) {
                switch (card.rank) {
                    case 2:
                    case 3:
                        if (demand != DRAW_DEMAND) continue;
                        break;
                    case 4:
                        if (demand != SKIP_DEMAND) continue;
                        break;
                    case JACK:
                        if (demand != RANK_DEMAND) continue;
                        break;
                    case KING:
                        if (card.suit < 3 && demand != DRAW_DEMAND) continue;
                        break;
                    case ACE:
                        if (demand != SUIT_DEMAND) continue;
                        break;
                }
            }

            if (
                demand == DRAW_DEMAND && (
                    card.rank == 2
                    || card.rank == 3
                    || card.rank == KING && st.battleKing > 0
                ) // playing multiple draws
                || demand == SKIP_DEMAND && card.rank == 4 // playing multiple skips
                || card.rank == ACE
                || card.rank == QUEEN
                || card.suit == top.suit
                || card.rank == top.rank
                || card.rank == JOKER
                || top.rank == QUEEN
            ) playableCards.push(card);
        }

        hand.setSelectionMode('single', playableCards);

        if (playCards.length > 0) {
            statusBar.setTitle('Click play when ready.');
            this.enablePlay();
            return;
        }

        if (playableCards.length < 1) statusBar.setTitle(_('You have no playable cards.'));
        else statusBar.setTitle(_('Pick cards to play.'));

        if (!st.drew && demand == '') this.enableDraw();
        else this.enablePass();
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
        let activePlayerId = this.bga.players.getActivePlayerId();
        let currentPlayerId = this.bga.players.getCurrentPlayerId();

        statusBar.removeActionButtons();

        if (activePlayerId == currentPlayerId) {
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
                    rank: ANY_RANK,
                });
            });
        } else {
            statusBar.setTitle(`${this.bga.players.getPlayerById(activePlayerId).name} is selecting a rank.`);
        }
    }

    pickSuit() {
        let statusBar = this.bga.statusBar;
        let st = this.st;
        statusBar.removeActionButtons();

        if (this.bga.players.getActivePlayerId() == this.playerId) {
            let cards = this.hand.cards;

            statusBar.setTitle('Select a suit.');
            this.suitButtons = [];
            let availableSuits = [];

            for (let i = 0; i < cards.length; i++) {
                let card = cards[i];
                if (card.rank < 5 || card.rank > 10) continue;
                // only non-action cards
                availableSuits[card.suit] = card.suit;
            }

            for (let i = 1; i < 5; i++) {
                if (availableSuits[i] == undefined) continue;
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
                    suit: ANY_SUIT
                });
            });
        }
    }

    stateUpdate(args) {
        for (var key in this.st) {
            if (args[key] != undefined) this.st[key] = args[key];
        }
    }

    async notif_Pass(args) {
        this.stateUpdate(args);

        this.setPlayOptions();
    }

    async notif_DrawCards(args) {
        this.stateUpdate(args);

        this.deck.setCardNumber(args.deck);

        if (args.reshuffled) {
            this.redoDiscards(args);
        }

        if (args._private) {
            await this.hand.addCards(Array.from(Object.values(args._private.cards)));
            this.st.drew = 1;
        } else {
            this.updateHandSize(args.playerId, args[`hand${args.playerId}`]);
        }

        this.setPlayOptions();
    }

    async notif_PlayCards(args) {
        this.stateUpdate(args);

        await this.discard.addCards(Array.from(Object.values(args.cards)));

        this.updateHandSize(args.playerId, args[`hand${args.playerId}`]);

        this.setPlayOptions();
    }

    async notif_NewHand(args) {
        let st = this.st;
        
        this.deck.setCardNumber(args.deck);

        await this.discard.removeAll();
        await this.discard.addCards(Array.from(Object.values(args.discard)));

        for (let i = 0; i < st.playerIds.length; i++) {
            let playerId = st.playerIds[i];
            if (playerId == st.playerId) continue;
            this.updateHandSize(playerId, args[`hand${playerId}`]);
        }

        await this.play.removeAll();

        await this.hand.removeAll();
        await this.hand.addCards(args._private.hand);

        this.stateUpdate(args);

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

    async notif_WinHand(args, notif) {
        this.bga.statusBar.setTitle('win');
    }

    async notif_InvalidPlay(args) {
        this.hand.unselectAll();

        this.setPlayOptions();
    }
}