<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein\States;

use Bga\Games\makaucloudnein\Game;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\UserException;
use Bga\GameFramework\Actions\Types\IntArrayParam;
use Bga\GameFramework\Actions\Types\JsonParam;
use Bga\GameFramework\NotificationMessage;

class GameOn extends GameState
{
    public array $st;

    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 31,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            descriptionMyTurn: clienttranslate('${you} may play cards or draw')
        );
    }

    function onEnteringState()
    {
        $this->gamestate->setAllPlayersMultiactive();
    }

    #[PossibleAction]
    public function actPass(int $currentPlayerId)
    {
        $game = $this->game;
        $st = json_decode($this->bga->globals->get('state'), true);

        if ($currentPlayerId != $st['currentPlayerId'])
            return $game->err($currentPlayerId, 'not your turn');

        if (
            $st['draw'] == 0
            && $st['skip'] == 0
            && $st["skip{$currentPlayerId}"] == 0
            && $st['suitDemand'] == 0
            && $st['rankDemand'] == 0
            && $st['drew'] == 0
        )
            return $game->err($currentPlayerId, 'have to draw before passing');

        $skip = $st["skip{$currentPlayerId}"] + $st['skip'];
        if ($skip > 0) {
            // I have more skips to skip
            $st["skip{$currentPlayerId}"] = $skip - 1;
            $st['skip'] = 0;
        } else {
            // only adjust other demands if I am not skipped
            if ($st['draw'] > 0) {
                $st['draw'] = 0;
            }

            $st['suitDemand'] = 0;

            $st['drew'] = 0;

            if ($st['lastJack'] == $currentPlayerId) {
                $st['lastJack'] = 0;
                $st['rankDemand'] = 0;
            }
        }

        if ($st['reverse'] > 0)
            $st['currentPlayerId'] = $this->prevPlayerId($currentPlayerId);
        else
            $st['currentPlayerId'] = $this->nextPlayerId($currentPlayerId);

        $this->bga->globals->set('state', json_encode($st));

        $st['playerId'] = $currentPlayerId;
        $st['playerName'] = $game->getPlayerNameById($currentPlayerId);

        $game->notify->all(
            'Pass',
            clienttranslate('${playerName} passes'),
            $st,
        );
    }

    #[PossibleAction]
    public function actDraw(int $currentPlayerId)
    {
        $game = $this->game;
        $cards = $game->cards;
        $st = json_decode($this->bga->globals->get('state'), true);

        if ($currentPlayerId != $st['currentPlayerId']) return $game->err($currentPlayerId, 'not your turn');

        if ($st['skip'] + $st["skip{$currentPlayerId}"] > 0) return $game->err($currentPlayerId, 'have to skip');
        if ($st['suitDemand'] > 0) return $game->err($currentPlayerId, 'have to match suit');

        if ($st['drew'] > 0) return $game->err($currentPlayerId, 'can only draw once');
        $forcedDraw = false;
        $draw = $st['draw'];
        if ($draw == 0) $draw = 1;
        else $forcedDraw = true;

        $st['reshuffled'] = false;
        $drawnCards = $cards->pickItems($draw, 'deck', ['hand', $currentPlayerId])->values();
        // this may have caused the discards to be reshuffled into a new deck...
        if ($cards->countItemsInLocation('discard') < 1) {
            foreach ($cards->getItemsInLocation('deck')->values() as $card) {
                if ($card->joker == 1) {
                    $card->rank = 15;
                    $card->suit = 0;
                    $cards->updateItem($card, ['rank', 'suit']);
                }
            }
            
            $discard = $cards->pickItem('deck', 'discard');
            while ($discard->rank < 5 || $discard->rank > 10)
                $discard = $cards->pickItem('deck', 'discard');
            $st['reshuffled'] = true;
            $st['discard'] = $cards->getItemsInLocation('discard')->values();
        }

        $st['drew'] = $draw;
        $st['deck'] = $cards->countItemsInLocation('deck');

        if ($forcedDraw) {
            $st['draw'] = 0;
            $st['reverse'] = 0;
            $st['battleKing'] = 0;
            $st['drew'] = 0;

            $st['currentPlayerId'] = $this->nextPlayerId($currentPlayerId);
        }

        $this->bga->globals->set('state', json_encode($st));

        $st['playerId'] = $currentPlayerId;
        $st['playerName'] = $game->getPlayerNameById($currentPlayerId);
        $st["hand{$currentPlayerId}"] = $cards->countItemsInLocation(['hand', $currentPlayerId]);
        $st['_private'] = [
            $currentPlayerId => [
                'cards' => $drawnCards
            ]
        ];

        $game->notify->all(
            'DrawCards',
            clienttranslate('${playerName} takes card(s) from the deck'),
            $st,
        );
    }

    #[PossibleAction]
    public function actPlay(#[IntArrayParam()] array $cardIds, #[JsonParam(associative: true)] array $jokers, int $currentPlayerId)
    {
        $game = $this->game;
        $cards = $game->cards;
        $st = json_decode($this->bga->globals->get('state'), true);

        if ($currentPlayerId != $st['currentPlayerId'])
            return $game->err($currentPlayerId, 'not your turn');

        if ($st["skip{$currentPlayerId}"] > 0) return $game->err($currentPlayerId, 'you must pass');
        if (count($cardIds) < 1) return $game->err($currentPlayerId, 'no cards to play');

        $playedCards = [];

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

        if ($st['skip'] > 0) {
            //////////////// pending skips            
            $playableCards = $this->getSkipCards($playedCards);
            if (count($playableCards) != count($playedCards))
                return $game->err($currentPlayerId, 'you can only add skip cards');

            for ($i = 0; $i < count($playableCards); $i++) {
                $st['skip']++;
            }
        } else if ($st['draw'] > 0) {
            //////////////// pending draw
            $playableCards = $this->getDrawCards($playedCards);
            if (count($playableCards) != count($playedCards))
                return $game->err($currentPlayerId, 'you can only add draw cards');
        } else if ($st['rankDemand']) {
            //////////////// rank demand
            if (count($playedCards) > 1)
                return $game->err($currentPlayerId, 'you can only play one card');

            $card = $playedCards[0];
            if (
                $card->rank == $st['rankDemand']
                || $card->rank == 11
            )
                $playableCards = [$card];
        } else if ($st['suitDemand']) {
            //////////////// suit demand
            if (count($playedCards) > 1)
                return $game->err($currentPlayerId, 'you can only play one card');

            $card = $playedCards[0];
            if (
                $card->rank > 4
                && $card->rank < 11
                && $card->suit == $st['suitDemand']
            )
                $playableCards = [$card];
        } else {
            //////////////// no pending demands
            $demand = '';

            //////////////// check that there are, at most, one demand type
            for ($i = 0; $i < count($playedCards); $i++) {
                $card = $playedCards[$i];

                $newdemand = $demand;

                switch ($card->rank) {
                    case 2:
                    case 3:
                        $newdemand = 'draw';
                        break;
                    case 4:
                        $newdemand = 'skip';
                        break;
                    case 11: // J
                        $newdemand = 'rank';
                        break;
                    case 13: // K
                        if ($card->suit < 3) $newdemand = 'draw';
                        break;
                    case 14: // A
                        $newdemand = 'suit';
                        break;
                }

                if ($demand != '' && $newdemand != $demand)
                    return $game->err($currentPlayerId, 'You cannot play multiple demands');

                $demand = $newdemand;
            }

            $top = $cards->getItemOnTop('discard');

            //////////////// check that the cards are actually playable
            for ($i = 0; $i < count($playedCards); $i++) {
                $card = $playedCards[$i];

                if ($demand != '') {
                    switch ($card->rank) {
                        case 2:
                            if ($demand != 'draw') return $game->err($currentPlayerId, 'You cannot play a two when another demand is in play');
                            break;
                        case 3:
                            if ($demand != 'draw') return $game->err($currentPlayerId, 'You cannot play a three when another demand is in play');
                            break;
                        case 4:
                            if ($demand != 'skip') return $game->err($currentPlayerId, 'You cannot play a four when another demand is in play');
                            break;
                        case 11: // J
                            if ($demand != 'rank') return $game->err($currentPlayerId, 'You cannot play a jack when another demand is in play');
                            break;
                        case 13: // K
                            if ($demand != 'draw') return $game->err($currentPlayerId, 'You cannot play a king when another demand is in play');
                            break;
                        case 14: // A
                            if ($demand != 'suit') return $game->err($currentPlayerId, 'You cannot play an ace when another demand is in play');
                            break;
                    }
                }

                if (
                    $demand == 'draw' && ($card->rank < 4 || $card->rank == 13)
                    || $demand = 'skip' && $card->rank == 4
                    || $card->rank == 14 // A = wild
                    || $card->rank == 12 // Q = wild
                    || $card->suit == $top->suit
                    || $card->rank == $top->rank
                    || $top->rank == 12 // wild = Q
                ) {
                    $playableCards[] = $card;
                    $top = $card;
                } else {
                    return $game->err(
                        $currentPlayerId,
                        'You cannot play ' . $this->getCardName($card) . ' on top of ' . $this->getCardName($top)
                    );
                }
            }
        }

        //////////// valid play, enact

        if ($st['lastJack'] == $currentPlayerId) {
            // clear previous Jack demand
            $st['lastJack'] = 0;
            $st['rankDemand'] = 0;
        }

        if ($st['reverse'] > 0)
            $st['currentPlayerId'] = $this->prevPlayerId($currentPlayerId);
        else
            $st['currentPlayerId'] = $this->nextPlayerId($currentPlayerId);

        for ($i = 0; $i < count($playedCards); $i++) {
            $card = $playedCards[$i];
            switch ($card->rank) {
                case 2:
                    $st['draw'] += 2;
                    break;
                case 3:
                    $st['draw'] += 3;
                    break;
                case 4:
                    $st['skip']++;
                    break;
                case 11:
                    // J demands rank
                    $st['rankPick'] = $currentPlayerId;
                    $st['currentPlayerId'] = $currentPlayerId;
                    break;
                case 13:
                    switch ($card->suit) {
                        case 1: // KS back draw 5
                            $st['currentPlayerId'] = $this->prevPlayerId($currentPlayerId);
                            $st['reverse'] = 1;
                        case 2: // KH draw 5
                            $st['battleKing'] = 5;
                            $st['draw'] += 5;
                            break;
                        case 3:
                        case 4:
                            // KD KC blocks battle kings
                            if ($st['battleKing'] > 0) {
                                $st['draw'] = 0;
                                $st['reverse'] = 0;
                            }
                            break;
                    }
                    break;
                case 14:
                    // A demands suit
                    $st['suitPick'] = $currentPlayerId;
                    $st['currentPlayerId'] = $currentPlayerId;
                    break;
            }
        }
        // move the cards to the discard pile
        // one at a time to maintain order
        for ($i = 0; $i < count($playedCards); $i++) {
            $cards->moveItem($playedCards[$i], 'discard');
        }

        if ($st['suitDemand']) {
            $st['suitDemand'] = 0;
        }

        if ($st['battleKing'] != 0 && $st['battleKing'] != $currentPlayerId)
            $st['battleKing'] = 0;

        $st['drew'] = 0;

        $handCards = $cards->countItemsInLocation(['hand', $currentPlayerId]);
        if ($handCards == 0) {
            $playerScore = $this->bga->playerScore;
            $playerScore->inc($currentPlayerId, 1);
            if ($playerScore->get($currentPlayerId) > 9) {
                // WINNER!
                return GameOver::class;
            }

            $st = $game->reset();
            $this->st = $st;
            $st['reshuffled'] = 1;
            $st['_private'] = [];
            for ($i = 0; $i < count($st['playerIds']); $i++) {
                $playerId = $st['playerIds'][$i];

                $st["hand{$playerId}"] = $cards->countItemsInLocation(['hand', $playerId]);

                $st["_private"][$playerId] = [
                    'hand' => $cards->getItemsInLocation(['hand', $playerId])
                ];
            }
        } else $this->bga->globals->set('state', json_encode($st));

        $st["hand{$currentPlayerId}"] = $handCards;
        $st['playerId'] = $currentPlayerId;
        $st['cards'] = $playedCards;
        $st['discard'] = $cards->getItemsInLocation('discard')->values();

        $game->notify->all('PlayCards', '', $st);
    }

    #[PossibleAction]
    public function actPickRank(int $rank, int $currentPlayerId)
    {
        $st = json_decode($this->bga->globals->get('state'), true);

        if ($st['rankPick'] != $currentPlayerId) return null;

        $st['rankPick'] = 0;

        $st['rankDemand'] = $rank;
        $st['lastJack'] = $currentPlayerId;

        if ($st['reverse'] > 0)
            $st['currentPlayerId'] = $this->prevPlayerId($currentPlayerId);
        else
            $st['currentPlayerId'] = $this->nextPlayerId($currentPlayerId);

        $this->bga->globals->set('state', json_encode($st));

        $this->game->bga->notify->all('Selected', 'Rank selected', $st);
    }

    #[PossibleAction]
    public function actPickSuit(int $suit, int $currentPlayerId)
    {
        $st = json_decode($this->bga->globals->get('state'), true);

        if ($st['suitPick'] != $currentPlayerId) return null;

        $st['suitPick'] = 0;

        $st['suitDemand'] = $suit;

        if ($st['reverse'] > 0)
            $st['currentPlayerId'] = $this->prevPlayerId($currentPlayerId);
        else
            $st['currentPlayerId'] = $this->nextPlayerId($currentPlayerId);

        $this->bga->globals->set('state', json_encode($st));

        $this->game->bga->notify->all('Selected', 'Suit selected', $st);
    }


    #[PossibleAction]
    public function actMakau(int $currentPlayerId, int $onPlayerId)
    {
        $globals = $this->bga->globals;
        $cards = $this->game->cards;

        $st = json_decode($this->bga->globals->get('state'), true);

        if ($st["makau{$onPlayerId}"] > 0) return;

        $st["makau{$onPlayerId}"] = $onPlayerId;

        if ($currentPlayerId != $onPlayerId) {
            $drawnCards = $cards->pickItems(5, 'deck', ['hand', $onPlayerId])->values();
            $st['deck'] = $cards->countItemsInLocation('deck');
        } else {
            $drawnCards = [];
        }

        $globals->set('state', json_encode($st));

        $st["hand{$onPlayerId}"] = $cards->countItemsInLocation(['hand', $onPlayerId]);
        $st['onPlayerId'] = $onPlayerId;
        $st['playerName'] = $this->game->getPlayerNameById($onPlayerId);
        if (count($drawnCards) > 0)
            $st['_private'] = [
                $onPlayerId => [
                    'cards' => $drawnCards
                ]
            ];

        $this->game->bga->notify->all('CalledMakau', clienttranslate('${playerName} called makau.'), $st);
    }

    public function zombie(int $playerId)
    {
        // We must implement this so BGA can auto play in the case a player becomes a zombie, but for this tutorial we won't handle this case
        throw new UserException("Not implemented: zombie for player {$playerId}");
    }

    function getDrawCards($cards): array
    {
        $matchingCards = [];

        for ($i = 0; $i < count($cards); $i++) {
            switch ($cards[$i]->rank) {
                case 2:
                case 3:
                case 13:
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
        return ('The ' . $this->game->cardTypes['ranks'][$card->rank]['name'] . " of " . $this->game->cardTypes['suits'][$card->suit]['name'] . 's');
    }

    function getCardNames($cards): string
    {
        return implode(", ", array_map(fn($card) => $this->getCardName($card), $cards));
    }

    function nextPlayerId($playerId): int
    {
        $game = $this->game;

        $playerNo = $game->getPlayerNoById($playerId) + 1;
        if ($playerNo > $game->getPlayerCount()) $playerNo = 1;
        return $game->getPlayerIdByNo($playerNo);
    }

    function prevPlayerId($playerId): int
    {
        $game = $this->game;

        $playerNo = $game->getPlayerNoById($playerId) - 1;
        if ($playerNo < 1) $playerNo = $game->getPlayerCount();
        return $game->getPlayerIdByNo($playerNo);
    }
}
