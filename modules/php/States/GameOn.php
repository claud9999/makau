<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein\States;

use Bga\Games\makaucloudnein\States\GameOver;
use Bga\Games\makaucloudnein\States\NextPlayer;
use Bga\Games\makaucloudnein\States\PrevPlayer;

use Bga\Games\makaucloudnein\Game;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\Actions\CheckAction;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\UserException;
use Bga\GameFramework\Actions\Types\IntArrayParam;
use Bga\GameFramework\Actions\Types\JsonParam;
use Bga\GameFramework\NotificationMessage;

/*
  This game supports players calling "Makau" on other
  players during their turns, so this code dispenses with state
  beyond this state and the GameOver state. Who is playing is
  stored in the state global.

  Any condition that results in a call to $game->err() is
  unexpected. The client UI should never produce conditions
  that would trigger these. Hence these messages are not translated.

  All state information is stored in a state variable ($st)
  and saved via the globals interface in the database as
  JSON. The state is usually also sent to the client in
  toto even though the client likely only needs a few
  fields as it makes the code simpler.

  For act* functions, the general pattern is that it loads
  the state from the global state JSON, checks conditions,
  executes changes, saves the state, adds any additional
  details for the client to know, then notifies the client.

  Most functions begin with the setting of local "shortcut"
  variables such as $game for $this->game or $cards for $this->cards
  just to make the code easier to read and possibly to give
  a slight performance improvement.
  */

class GameOn extends GameState
{
    public array $st;

    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 31,
            type: StateType::ACTIVE_PLAYER,
            descriptionMyTurn: clienttranslate('...')
        );
    }

    function onEnteringState() {}

    #[PossibleAction]
    public function actPass(int $currentPlayerId)
    {
        $game = $this->game;

        $st = json_decode($this->bga->globals->get('state'), true);

        if (
            $st['demand'] == Game::NO_DEMAND
            && $st["skip{$currentPlayerId}"] == 0
            && $st['drew'] == 0
        )
            return $game->err($currentPlayerId, 'You have to draw before passing.');

        $skip = $st["skip{$currentPlayerId}"];
        if ($st['demand'] == Game::SKIP_DEMAND) $skip += $st['demandArg'];
        if ($skip > 0) {
            // I have more skips to skip
            $st["skip{$currentPlayerId}"] = $skip - 1;
            $st['demand'] = Game::NO_DEMAND;
            $st['demandArg'] = 0;
        } else {
            // if we are not skipping, reset demands
            switch ($st['demand']) {
                case Game::DRAW_DEMAND:
                case Game::SUIT_DEMAND:
                    $st['demand'] = Game::NO_DEMAND;
                    $st['demandArg'] = 0;
                    break;
                case Game::RANK_DEMAND:
                    if ($st['lastJack'] == $currentPlayerId) {
                        $st['demand'] = Game::NO_DEMAND;
                        $st['demandArg'] = 0;
                        $st['lastJack'] = 0;
                    }
                    break;
            }
        }
        $st['drew'] = 0;

        $this->bga->globals->set('state', json_encode($st));

        // vvvvvv parameters not saved but sent to the client
        $st['playerId'] = $currentPlayerId;
        $st['playerName'] = $game->getPlayerNameById($currentPlayerId);

        $game->notify->all(
            'Pass',
            clienttranslate('${playerName} passes.'),
            $st,
        );

        if ($st['reverse'] > 0) return PrevPlayer::class;
        else return NextPlayer::class;
    }

    #[PossibleAction]
    public function actDraw(int $currentPlayerId)
    {
        $game = $this->game;
        $cards = $game->cards;

        $st = json_decode($this->bga->globals->get('state'), true);

        $demand = $st['demand'];

        if ($st["skip{$currentPlayerId}"] > 0) return $game->err($currentPlayerId, 'You have to skip.');

        if (
            $demand != Game::NO_DEMAND
            && $demand != Game::DRAW_DEMAND
        )
            return $game->err($currentPlayerId, 'You have to meet the demand or pass.');

        if ($st['drew'] > 0) return $game->err($currentPlayerId, 'You can only draw once.');

        if ($demand == Game::DRAW_DEMAND) $draw = $st['demandArg'];
        else $draw = 1;

        $st['reshuffled'] = false;
        $drawnCards = $cards->pickItems($draw, 'deck', ['hand', $currentPlayerId])->values();
        // this may have caused the discards to be reshuffled into a new deck...
        if ($cards->countItemsInLocation('discard') < 1) {
            // reset jokers in the deck
            foreach ($cards->getItemsInLocation('deck')->values() as $card) {
                if ($card->joker == 1) {
                    $card->rank = 14;
                    $card->suit = 0;
                    $cards->updateItem($card, ['rank', 'suit']);
                }
            }

            // build a new discard stack
            $discard = $cards->pickItem('deck', 'discard');
            while ($discard->rank < 5 || $discard->rank > 10)
                $discard = $cards->pickItem('deck', 'discard');
            $st['reshuffled'] = true;
            $st['discard'] = $cards->getItemsInLocation('discard')->values();
        }

        $st['deck'] = $cards->countItemsInLocation('deck');

        if ($demand == Game::DRAW_DEMAND) {
            $st['demand'] = Game::NO_DEMAND;
            $st['reverse'] = 0;
            $st['battleKing'] = 0;
            $st['drew'] = 0;
        } else
            $st['drew'] = $draw;

        $this->bga->globals->set('state', json_encode($st));

        // vvvvvv parameters not saved but sent to the client
        $st['playerId'] = $currentPlayerId;
        $st['playerName'] = $game->getPlayerNameById($currentPlayerId);
        $st['playerDrew'] = $draw;
        $st["hand{$currentPlayerId}"] = $cards->countItemsInLocation(['hand', $currentPlayerId]);
        $st['_private'] = [
            $currentPlayerId => [
                'cards' => $drawnCards
            ]
        ];

        $message = '${playerName} draws a card from the deck.';
        if ($draw > 1) $message = '${playerName} draws ${playerDrew} cards from the deck.';
        $game->notify->all(
            'DrawCards',
            clienttranslate($message),
            $st,
        );

        if ($demand == Game::DRAW_DEMAND) return NextPlayer::class;
    }

    #[PossibleAction]
    public function actPlay(#[IntArrayParam()] array $cardIds, #[JsonParam(associative: true)] array $jokers, int $currentPlayerId)
    {
        $game = $this->game;
        $cards = $game->cards;

        $st = json_decode($this->bga->globals->get('state'), true);

        if ($st["skip{$currentPlayerId}"] > 0) return $game->err($currentPlayerId, 'You must pass.');
        if (count($cardIds) < 1) return $game->err($currentPlayerId, 'You have no cards to play.');

        $playedCards = [];

        // remap Jokers to what the player chose
        for ($i = 0; $i < count($cardIds); $i++) {
            $cardId = $cardIds[$i];
            $card = $cards->getItemById($cardId);
            $playedCards[] = $card;
            if (array_key_exists($cardId, $jokers)) {
                $card->rank = $jokers[$cardId]['rank'];
                $card->suit = $jokers[$cardId]['suit'];
                $cards->updateItem($card, ['rank', 'suit']);
            }
        }

        $playedCount = count($playedCards);

        $demand = $st['demand'];
        $demandArg = $st['demandArg'];
        $battleKing = $st['battleKing'];
        $lastJack = $st['lastJack'];
        $reverse = $st['reverse'];
        $rankPick = 0;
        $suitPick = 0;

        if ($demand == Game::NO_DEMAND) {
            // compute new demand based on what's being played
            $card = $playedCards[0];
            switch ($card->rank) {
                case Game::ACE:
                    if ($playedCount > 1)
                        return $game->err($currentPlayerId, "You can only play one card when making a demand.");
                    $suitPick = $currentPlayerId;
                    break;
                case 2:
                case 3:
                    $demand = Game::DRAW_DEMAND;
                    break;
                case 4:
                    $demand = Game::SKIP_DEMAND;
                    break;
                case Game::JACK:
                    $demand = Game::RANK_DEMAND;
                    if ($playedCount > 1)
                        return $game->err($currentPlayerId, "You can only play one card when making a demand.");
                    $rankPick = $currentPlayerId;
                    break;
                case Game::KING:
                    switch ($card->suit) {
                        case Game::SPADE:
                        case Game::HEART:
                            $demand = Game::DRAW_DEMAND;
                    }
            }
        }

        switch ($demand) {
            case Game::NO_DEMAND:
                $top = $playedCards[0];
                for ($i = 1; $i < $playedCount; $i++) {
                    $card = $playedCards[$i];
                    switch ($card->rank) {
                        case 2:
                        case 3:
                        case 4:
                        case Game::KING:
                            if ($card->suit == Game::DIAMOND || $card->suit == Game::CLUB) break;
                        case Game::JACK:
                        case Game::ACE:
                            return $game->err($currentPlayerId, 'You cannot play demands with non-action cards.');
                            break;
                    }
                    if (
                        $top->rank != Game::QUEEN
                        && $card->rank != Game::QUEEN
                        && $card->suit != $top->suit
                        && abs($card->rank - $top->rank) > 1
                    ) return $game->err($currentPlayerId, 'Cards must be in sequence or the same rank.');
                    $top = $card;
                }
                break;
            case Game::DRAW_DEMAND:
                foreach ($playedCards as $card) {
                    switch ($card->rank) {
                        case 2:
                            $demandArg += 2;
                            break;
                        case 3:
                            $demandArg += 3;
                            break;
                        case Game::KING:
                            switch ($card->suit) {
                                case Game::SPADE:
                                    $reverse = $currentPlayerId;
                                    // and fall through
                                case Game::HEART:
                                    $demandArg += 5;
                                    $battleKing = $currentPlayerId;
                                    break;
                                case Game::DIAMOND:
                                case Game::CLUB:
                                    // clear battle king
                                    $battleKing = 0;
                                    $demandArg = 0;
                                    $demand = Game::NO_DEMAND;
                                    break;
                            }
                            break;
                        default:
                            return $game->err($currentPlayerId, "You can only play draw cards.");
                    }
                }
                break;
            case Game::SKIP_DEMAND:
                foreach ($playedCards as $card) {
                    if (
                        $card->rank != 4
                    ) return $game->err($currentPlayerId, "You can only play skip cards.");
                    $demandArg++;
                }
                break;
            case Game::RANK_DEMAND:
                if ($playedCount > 1)
                    return $game->err($currentPlayerId, "You can only play one card.");

                $card = $playedCards[0];
                if ($card->rank == Game::JACK) {
                    $rankPick = $currentPlayerId;
                } else if ($card->rank != $demandArg)
                    return $game->err($currentPlayerId, "You need to play the rank.");
                else if ($currentPlayerId == $lastJack) {
                    $lastJack = 0;
                    $demand = 0;
                    $demandArg = 0;
                }
                break;
            case Game::SUIT_DEMAND:
                if ($playedCount > 1)
                    return $game->err($currentPlayerId, "You can only play one card.");
                if (
                    $playedCards[0]->suit != $demandArg
                    || $playedCards[0]->rank < 5
                    || $playedCards[0]->rank > 10
                ) return $game->err($currentPlayerId, "You need to play the suit.");

                // we've cleared demand
                $demand = Game::NO_DEMAND;
                $demandArg = 0;
                break;
        }

        //////////// valid play, enact
        $st['demand'] = $demand;
        $st['demandArg'] = $demandArg;
        $st['battleKing'] = $battleKing;
        $st['lastJack'] = $lastJack;
        $st['reverse'] = $reverse;
        $st['rankPick'] = $rankPick;
        $st['suitPick'] = $suitPick;

        $st['drew'] = 0;

        // move the cards to the discard pile
        // one at a time to maintain order
        foreach ($playedCards as $card) {
            $cards->moveItem($card, 'discard');
        }

        $handCards = $cards->countItemsInLocation(['hand', $currentPlayerId]);
        if ($handCards == 0) {
            ////// WINNNNNNNNNNNN

            // Note: no point saving the current state, it will be reset below.

            $st['playerName'] = $game->getPlayerNameById($currentPlayerId);
            $game->notify->all('WinHand', clienttranslate('${playerName} wins this hand!'), $st);

            $playerScore = $this->bga->playerScore;
            $playerScore->inc($currentPlayerId, 1);
            if ($playerScore->get($currentPlayerId) > 9) {
                return GameOver::class;
            }

            $st = $game->reset();

            $this->st = $st;

            $this->bga->globals->set('state', json_encode($st));

            // vvvvvv parameters not saved but sent to the client
            $st['reshuffled'] = 1;

            $st['_private'] = [];
            for ($i = 0; $i < count($st['playerIds']); $i++) {
                $playerId = $st['playerIds'][$i];

                $st["hand{$playerId}"] = $cards->countItemsInLocation(['hand', $playerId]);

                $st["_private"][$playerId] = [
                    'hand' => $cards->getItemsInLocation(['hand', $playerId])
                ];
            }

            $st['playerId'] = $currentPlayerId;
            $st['discard'] = $cards->getItemsInLocation('discard')->values();

            $game->notify->all('NewHand', clienttranslate('The deck is shuffled and a new hand dealt to each player.'), $st);
        } else {
            $this->bga->globals->set('state', json_encode($st));

            $message = '${playerName} plays the ';
            for ($i = 0; $i < count($playedCards); $i++) {
                $message = $message . $this->getCardName($playedCards[$i]);
                if ($i < count($playedCards)) $message = $message . ', ';
            }
            $message = $message . '.';

            // vvvvvv parameters not saved but sent to the client
            $st['playerName'] = $this->game->getPlayerNameById($currentPlayerId);
            $st["hand{$currentPlayerId}"] = $handCards;
            $st['playerId'] = $currentPlayerId;
            $st['cards'] = $playedCards;
            $st['discard'] = $cards->getItemsInLocation('discard')->values();

            $game->notify->all('PlayCards', clienttranslate($message), $st);
        }

        if ($rankPick == 0 && $suitPick == 0) {
            if ($st['reverse'] > 0) return PrevPlayer::class;
            else return NextPlayer::class;
        }
    }

    #[PossibleAction]
    public function actPickRank(int $rank, int $currentPlayerId)
    {
        $game = $this->game;

        $st = json_decode($this->bga->globals->get('state'), true);

        if ($st['rankPick'] != $currentPlayerId) return null;

        $st['rankPick'] = 0;
        $st['demand'] = GAME::RANK_DEMAND;
        $st['demandArg'] = $rank;
        $st['lastJack'] = $currentPlayerId;

        $this->bga->globals->set('state', json_encode($st));

        // vvvvvv parameters not saved but sent to the client
        $st['playerName'] = $game->getPlayerNameById($currentPlayerId);
        if ($rank == Game::ANY_RANK) $st['rankDemandName'] = 'any rank';
        else $st['rankDemandName'] = $game->cardTypes['rank'][$rank]['name'];

        $this->game->bga->notify->all('Selected', clienttranslate('${playerName} demands everyone play ${rankDemandName}.'), $st);

        if ($st['reverse'] > 0) return PrevPlayer::class;
        else return NextPlayer::class;
    }

    #[PossibleAction]
    public function actPickSuit(int $suit, int $currentPlayerId)
    {
        $game = $this->game;

        $st = json_decode($this->bga->globals->get('state'), true);

        if ($st['suitPick'] != $currentPlayerId) return null;

        $st['suitPick'] = 0;
        $st['demand'] = GAME::SUIT_DEMAND;
        $st['demandArg'] = $suit;

        $this->bga->globals->set('state', json_encode($st));

        // vvvvvv parameters not saved but sent to the client
        $st['playerName'] = $game->getPlayerNameById($currentPlayerId);
        if ($suit == Game::ANY_SUIT)
            $st['suitName'] = 'any suit';
        else
            $st['suitName'] = $game->cardTypes['suit'][$st['demandArg']]['name'] . ' suit';

        $this->game->bga->notify->all('Selected', clienttranslate('${playerName} demands the next player play a non-action card of ${suitName}.'), $st);

        if ($st['reverse'] > 0) return PrevPlayer::class;
        else return NextPlayer::class;
    }


    #[CheckAction(false)]
    public function actMakau(int $onPlayerId, int $currentPlayerId)
    {
        $game = $this->game;
        $globals = $this->bga->globals;
        $cards = $game->cards;

        $st = json_decode($this->bga->globals->get('state'), true);

        if ($st["makau{$onPlayerId}"] > 0 || $cards->countItemsInLocation(['hand', $onPlayerId]) != 1) {
            return;
        }

        $st["makau{$onPlayerId}"] = $currentPlayerId;

        if ($currentPlayerId != $onPlayerId)
            $drawnCards = $cards->pickItems(5, 'deck', ['hand', $onPlayerId])->values();

        $globals->set('state', json_encode($st));

        // vvvvvv parameters not saved but sent to the client
        $st["hand{$onPlayerId}"] = $cards->countItemsInLocation(['hand', $onPlayerId]);
        $st['onPlayerId'] = $onPlayerId;
        $st['playerName'] = $this->game->getPlayerNameById($currentPlayerId);
        $st['deck'] = $cards->countItemsInLocation('deck');

        $message = '${playerName} called Makau!';

        if ($currentPlayerId != $onPlayerId) {
            $st['_private'] = [
                $onPlayerId => [
                    'cards' => $drawnCards
                ]
            ];

            $st['onPlayerName'] = $this->game->getPlayerNameById($onPlayerId);
            $message = '${playerName} called Makau on ${onPlayerName}, who draws 5!';
        }

        $game->bga->notify->all('CalledMakau', clienttranslate($message), $st);

        return GameOn::class;
    }

    public function zombie(int $playerId)
    {
        $game = $this->game;
        $cards = $game->cards;
        $discards = $cards->getItemsInLocation('discard');
        $top = $discards->last();

        $handCards = $cards->getItemsInLocation(['hand', $playerId]);

        $st = json_decode($this->bga->globals->get('state'), true);

        if ($st["skip{$playerId}"] > 0) return $this->actPass($playerId);

        $jokerRank = 0;
        $jokerSuit = 0;

        // compute which cards can be played
        $playableCards = [];
        switch ($st['demand']) {
            case Game::NO_DEMAND:
                $jokerRank = 4;
                $jokerSuit = $top->suit;

                foreach ($handCards as $card) {
                    if (
                        $top->rank == Game::QUEEN
                        || $card->rank == Game::ACE
                        || $card->rank == Game::QUEEN
                        || $card->rank == Game::JOKER
                        || $card->suit == $top->suit && (
                            $card->rank > 4 && $card->rank < 11
                            && abs($card->rank - $top->rank) < 2
                        )
                        || $card->rank == $top->rank
                    )
                        $playableCards[] = $card;
                }
                break;
            case Game::DRAW_DEMAND:
                $jokerRank = 2;
                $jokerSuit = $top->suit;

                foreach ($handCards as $card) {
                    if (
                        $card->rank == 2
                        || $card->rank == 3
                        || $card->rank == Game::KING && (
                            $card->suit < 3
                            || $st['kingDemand'] != 0
                        )
                    ) $playableCards[] = $card;
                }
                break;
            case Game::SKIP_DEMAND:
                foreach ($handCards as $card) {
                    $jokerRank = 4;
                    $jokerSuit = $top->suit;

                    if (
                        $card->rank == 4
                    ) $playableCards[] = $card;
                }
                break;
            case Game::RANK_DEMAND:
                $jokerRank = $st['demandArg'];
                $jokerSuit = $top->suit;

                foreach ($handCards as $card) {
                    if ($card->rank == Game::JOKER) {
                        $card->rank = Game::JACK;
                        $card->suit = Game::HEART;
                        $playableCards[] = $card;
                    } else if (
                        $card->rank == $st['demandArg']
                    ) $playableCards[] = $card;
                }
                break;
            case Game::SUIT_DEMAND:
                $jokerRank = rand(5, 10);
                $jokerSuit = $st['demandArg'];

                foreach ($handCards as $card) {
                    if (
                        $card->suit == $st['demandArg']
                        && $card->rank > 4 && $card->rank < 11
                    ) $playableCards[] = $card;
                }
                break;
        }

        if (count($playableCards) < 1) {
            // draw, if I can.
            if ($st['drew'] == 0) {
                $drawResult = $this->actDraw($playerId);
                if ($drawResult != null) return $drawResult;
                return $this->zombie($playerId);
            }

            return $this->actPass($playerId);
        }

        $card = $playableCards[array_rand($playableCards)];

        if ($card->rank == Game::JOKER) {
            $jokers = [
                $card->id => ['rank' => $jokerRank, 'suit' => $jokerSuit]
            ];
        }
        $jokers = [];

        return $this->actPlay([$card->id], $jokers, $playerId);
    }

    function getDrawCards($cards): array
    {
        $matchingCards = [];

        for ($i = 0; $i < count($cards); $i++) {
            switch ($cards[$i]->rank) {
                case 2:
                case 3:
                case Game::KING:
                    $matchingCards[] = $cards[$i];
                    break;
            }
        }

        return $matchingCards;
    }

    function getSkipCards($cards): array
    {
        $matchingCards = [];

        for ($i = 0; $i < count($cards); $i++) {
            if ($cards[$i]->rank == 4)
                $matchingCards[] = $cards[$i];
        }

        return $matchingCards;
    }

    function getCardName($card): string
    {
        return ($this->game->cardTypes['rank'][$card->rank]['name'] . " of " . $this->game->cardTypes['suit'][$card->suit]['name'] . 's');
    }

    function getCardNames($cards): string
    {
        return implode(", ", array_map(fn($card) => $this->getCardName($card), $cards));
    }

    /*    function debug_playToEndRound() {
// actually only does one move, whatev!
        foreach($this->gamestate->getActivePlayerList() as $playerId) {
            $playerId = (int)$playerId;
            $this->gamestate->runStateClassZombie($this->gamestate->getCurrentState($playerId), $playerId);
        }
    }*/
}
