<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein\States;

use Bga\Games\makaucloudnein\Game;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\UserException;
use Bga\GameFramework\Actions\Types\IntArrayParam;
use Bga\GameFramework\NotificationMessage;


class GameOn extends GameState
{
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
        $globals = $this->bga->globals;
        $game = $this->game;

        if ($currentPlayerId != $globals->get('fw_player_id'))
            return $game->err($currentPlayerId, 'can\'t pass');

        $skip = $globals->get("skip_{$currentPlayerId}") + $globals->get('skip');

        // I have more skips to skip
        if ($skip > 0) {
            $globals->set("skip_{$currentPlayerId}", $skip - 1);
            $globals->set('skip', 0);
        }

        // carry draws forward
        $draw = $globals->get("draw_{$currentPlayerId}") + $globals->get('draw');
        if ($draw > 0) {
            $globals->set("draw_{$currentPlayerId}", $draw);
            $globals->set('draw', 0);
        }

        if (!$skip && $draw) return $game->err($currentPlayerId, 'can\'t pass when you have to draw');
        $globals->set('suit_demand', 0);
        $fw_player_id = $this->nextPlayerId($currentPlayerId);
        $globals->set('player_id', $player_id);

        $game->notify->all(
            'Pass',
            clienttranslate('${player_name} passes'),
            [
                'player_id' => $currentPlayerId,
                'player_name' => $game->getPlayerNameById($currentPlayerId),
                'fw_player_id' => $fw_player_id,
                'skip' => $skip,
                'draw' => $draw,
            ]
        );
    }

    function bwDraw(int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;

        $draw = $globals->get('bw_draw');
        $globals->set('bw_draw', 0);
        $globals->set('bw_player_id', 0);
        $drawnCards = $cards->pickItems($draw, 'deck', ['hand', $currentPlayerId])->values();

        $args = [
            'deck' => $cards->countItemsInLocation('deck'),
            'player_name' => $game->getPlayerNameById($currentPlayerId),
            'player_ids' => [$currentPlayerId],
            'bw_player_id' => 0,
            'draw' => $draw,
            "hand_{$currentPlayerId}" => $cards->countItemsInLocation(['hand', $currentPlayerId]),
            '_private' => [
                $currentPlayerId => [
                    'cards' => $drawnCards
                ]
            ]
        ];

        $game->notify->all(
            'DrawCards',
            clienttranslate('${player_name} takes card(s) from the deck'),
            $args
        );
    }

    #[PossibleAction]
    public function actDraw(int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;

        // TODO: it's possible I'm both the forward and backward player, how to handle?
        if ($globals->get('bw_player_id')) return bwDraw($currentPlayerId);

        $fw_player_id = $globals->get('fw_player_id');

        if ($currentPlayerId != $fw_player_id) return $game->err($currentPlayerId, 'not your turn');

        $draw = $globals->get('draw');
        $draw_me = $globals->get("draw_{$currentPlayerId}");

        if ($globals->get('skip') + $globals->get("skip_{$currentPlayerId}") > 0) return $game->err($currentPlayerId, 'have to skip');
        if ($globals->get('suit_demand') > 0) return $game->err($currentPlayerId, 'have to match suit');

        if ($globals->get('drew')) return $game->err($currentPlayerId, 'can only draw once');
        $forcedDraw = false;
        $draw += $draw_me;
        if ($draw == 0) $draw = 10;
        else $forcedDraw = true;

        $drawnCards = $cards->pickItems($draw, 'deck', ['hand', $currentPlayerId])->values();

        $globals->set('drew', $draw);

        $args = [
            'deck' => $cards->countItemsInLocation('deck'),
            'player_name' => $game->getPlayerNameById($currentPlayerId),
            'player_ids' => [$currentPlayerId],
            'draw' => $draw,
            "hand_{$currentPlayerId}" => $cards->countItemsInLocation(['hand', $currentPlayerId]),
            '_private' => [
                $currentPlayerId => [
                    'cards' => $drawnCards
                ]
            ]
        ];

        if ($forcedDraw) {
            $globals->set('draw', 0);
            $globals->set("draw_{$currentPlayerId}", 0);

            $fw_player_id = $this->nextPlayerId($currentPlayerId);
            $args['fw_player_id'] = $fw_player_id;
            $globals->set('fw_player_id', $fw_player_id);
        }

        $game->notify->all(
            'DrawCards',
            clienttranslate('${player_name} takes card(s) from the deck'),
            $args
        );
    }

    public function bwPlay(#[IntArrayParam()] array $cardIds, int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;

        if ($currentPlayerId != $globals->get('bw_player_id')) return $game->err($currentPlayerId, 'not current player');

        if ($globals->get("draw_{$currentPlayerId}") > 0) return $game->err($currentPlayerId, 'you cannot play');

        $discards = $cards->getItemsInLocation('discard');
        $top = $cards->getItemOnTop('discard');
        $playedCards = [];

        for ($i = 0; $i < count($cardIds); $i++) {
            $playedCards[] = $cards->getItemById($cardIds[$i]);
        }

        $playableCards = $this->getDrawCards($playedCards);

        if (count($playableCards) < count($playedCards)) {
            return $game->err(
                $currentPlayerId,
                'You cannot play ' . $this->getCardName($playedCards[count($playableCards)])
            );
        }

        $draw = $globals->get('draw');
        $draw_add = false;

        for ($i = 0; $i < count($playedCards); $i++) {
            $card = $playedCards[$i];
            if ($card->rank == 2 || $card->rank == 3) {
                $draw += $card->rank;
                $draw_add = true;
            }
            if ($card->rank == 13) {
                if ($card->suit == 1 || $card->suit == 2) {
                    $draw += 5;
                    $draw_add = true;
                } else {
                    $draw = 0;
                    $draw_add = 0;
                }
            }
        }

        if ($draw > 0) {
            if ($draw_add) {
                $globals->set('draw', $draw);
            } else {
                return $game->err($currentPlayerId, 'you must draw');
            }
        }

        // valid play, move the cards to the discard pile
        // one at a time to maintain order
        for ($i = 0; $i < count($playedCards); $i++) {
            $cards->moveItem($playedCards[$i], 'discard');
        }

        $game->notify->all('BWPlayCards', '', [
            'cards' => $playedCards,
            'bw_player_id' => $this->prevPlayerId($currentPlayerId),
            "hand_{$currentPlayerId}" => $cards->countItemsInLocation(['hand', $currentPlayerId]),
        ]);
    }

    #[PossibleAction]
    public function actPlay(#[IntArrayParam()] array $cardIds, int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;

        if ($currentPlayerId == $globals->get('bw_player_id')) return bwPlay($cardIds, $currentPlayerId);
        if ($currentPlayerId != $globals->get('fw_player_id')) return null;

        if ($globals->get("draw_{$currentPlayerId}") > 0) return $game->err($currentPlayerId, 'you cannot play');
        if ($globals->get("skip_{$currentPlayerId}") > 0) return $game->err($currentPlayerId, 'you cannot play');

        $discards = $cards->getItemsInLocation('discard');
        $top = $cards->getItemOnTop('discard');
        $playedCards = [];

        for ($i = 0; $i < count($cardIds); $i++) {
            $playedCards[] = $cards->getItemById($cardIds[$i]);
        }

        $playableCards = $this->getPlayableCards($top, $playedCards, $currentPlayerId);

        if (count($playableCards) < count($playedCards)) {
            return $game->err(
                $currentPlayerId,
                'You cannot play ' . $this->getCardName($playedCards[count($playableCards)])
            );
        }

        $draw = $globals->get('draw');
        $draw_add = false;
        $skip = $globals->get('skip');
        $skip_add = false;
        $suit_demand = $globals->get('suit_demand');
        $rank_demand = $globals->get('rank_demand');
        $last_jack = $globals->get('last_jack');
        $rankpick = 0;
        $suitpick = 0;
        $fw_player_id = $this->nextPlayerId($currentPlayerId);
        $bw_player_id = 0;
        $bwdraw = 0;

        for ($i = 0; $i < count($playedCards); $i++) {
            $card = $playedCards[$i];
            if ($card->rank == 2 || $card->rank == 3) {
                $draw += $card->rank;
                $draw_add = true;
            }
            if ($card->rank == 13 && $card->suit == 1) {
                // KS prev draw 5
                $bwdraw = 5;
                $bw_player_id = $this->prevPlayerId($currentPlayerId);
            }
            if ($card->rank == 13 && $card->suit == 2) {
                // KH draw 5
                $draw += 5;
                $draw_add = true;
            }
            if ($card->rank == 4) {
                $skip += 1;
                $skip_add = true;
            }
            if ($card->rank == 11) {
                // J demands rank
                $rankpick = $currentPlayerId;
                $fw_player_id = $currentPlayerId;
            }
            if ($card->rank == 14) {
                // A demands suit
                $suitpick = $currentPlayerId;
                $fw_player_id = $currentPlayerId;
            }
        }

        if ($draw > 0) {
            if ($draw_add) $globals->set('draw', $draw);
            else return $game->err($currentPlayerId, 'you must draw');
        }

        if ($skip > 0) {
            if ($skip_add) $globals->set('skip', $skip);
            else return $game->err($currentPlayerId, 'you must pass');
        }

        // valid play, move the cards to the discard pile
        // one at a time to maintain order
        for ($i = 0; $i < count($playedCards); $i++) {
            $cards->moveItem($playedCards[$i], 'discard');
        }

        if ($suit_demand) {
            $globals->set('suit_demand', 0);
            $rank_demand = 0;
        }


        $game->notify->all('PlayCards', '', [
            'player_id' => $currentPlayerId,
            'cards' => $playedCards,
            'draw' => $draw,
            'bwdraw' => $bwdraw,
            'skip' => $skip,
            'suit_demand' => $suit_demand,
            'rank_demand' => $rank_demand,
            'rankpick' => $rankpick,
            'suitpick' => $suitpick,
            'last_jack' => $last_jack,
            'fw_player_id' => $fw_player_id,
            'bw_player_id' => $bw_player_id,
            'hand_size' => $cards->countItemsInLocation(['hand', $currentPlayerId]),
        ]);
    }

    #[PossibleAction]
    public function actPickRank(int $rank, int $currentPlayerId)
    {
        // TODO: how to identify if the player is picking
        $globals = $this->bga->globals;
        if ($globals->get('rankpick') != $currentPlayerId) return null;

        $globals->set('rank_demand', $rank);
        $globals->set('last_jack', $currentPlayerId);

        $this->game->bga->notify->all('RankDemand', '', [
            'last_jack' => $currentPlayerId,
            'rank_demand' => $rank,
        ]);
    }

    #[PossibleAction]
    public function actPickSuit(int $suit, int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        if ($globals->get('suitpick') != $currentPlayerId) return null;

        $globals->set('suit_demand', $suit);

        $this->game->bga->notify->all('SuitDemand', '', [
            'suit' => $suit,
        ]);
    }

    public function zombie(int $playerId)
    {
        // We must implement this so BGA can auto play in the case a player becomes a zombie, but for this tutorial we won't handle this case
        throw new UserException("Not implemented: zombie for player ${playerId}");
    }

    function getDrawCards($cards): array
    {
        $matchingCards = [];

        for ($i = 0; $i < count($cards); $i++) {
            $card = $cards[$i];

            if (
                $card->rank == 2
                || $card->rank == 3
                //                    || $card->rank == 13 && $card->suit == 1 // KS
                || $card->rank == 13 && $card->suit == 2 // KH
            )
                $matchingCards[] = $card;
        }

        return $matchingCards;
    }

    /* Logic:
    If there is a "draw demand", the only cards the current player can play is another draw card.
    If there is a "skip demand", the only cards the current player can play is another skip card.
    If the previous card was an A and a suit was called, only that suit can be played. If "Free" was called, any non-action card.
    If the a player played a J and a rank was called, only that rank can be played, or another J. If "Any" was called, any non-action card or another J.
    If no skip and no draw demanded, play matching suit or rank or a wild card (A, Q).

    Jokers, when played, are declared as to what kind of card they represent.

    Should match getPlayableCards in Game.js
    */
    function getPlayableCards($top, $cards, $currentPlayerId): array
    {
        $globals = $this->bga->globals;
        $matchingCards = [];
        $rank_demand = $globals->get('rank_demand');
        $suit_demand = $globals->get('suit_demand');
        $draw = $globals->get('draw');
        $skip = $globals->get('skip');

        for ($i = 0; $i < count($cards); $i++) {
            $card = $cards[$i];
            $playable = false;

            if (
                $skip > 0
                && $card->rank == 4
            ) $playable = true;


            if (
                $draw > 0
                && (
                    $card->rank == 2
                    || $card->rank == 3
                    //                    || $card->rank == 13 && $card->suit == 1 // KS
                    || $card->rank == 13 && $card->suit == 2 // KH
                )
            )
                $playable = true;

            if (
                $rank_demand > 0
                &&
                (
                    $card->rank == $rank_demand
                    || $card->rank == 11
                )
            )
                $playable = true;

            if (
                $suit_demand > 0
                && $card->suit == $suit_demand
            )
                $playable = true;

            if (
                $skip == 0
                && $draw == 0
                && $rank_demand == 0
                && $suit_demand == 0
                &&
                (
                    $card->rank == 14 // A wild
                    || $card->rank == 12 // Q wild
                    || $card->suit == $top->suit
                    || $card->rank == $top->rank
                    || $top->rank == 12 // wild Q
                )
            )
                $playable = true;

            if ($playable) {
                $matchingCards[] = $card;
                $top = $card;
            }
        }

        return $matchingCards;
    }

    function getCardName($card): string
    {
        return ('The ' . $this->game->card_types['ranks'][$card->rank]['name'] . " of " . $this->game->card_types['suits'][$card->suit]['name'] . 's');
    }

    function getCardNames($cards): string
    {
        return implode(", ", array_map(fn($card) => $this->getCardName($card), $cards));
    }

    function nextPlayerId($player_id): int
    {
        $game = $this->game;

        $player_no = $game->getPlayerNoById($player_id) + 1;
        if ($player_no > $game->getPlayerCount()) $player_no = 1;
        return $game->getPlayerIdByNo($player_no);
    }

    function prevPlayerId($player_id): int
    {
        $game = $this->game;

        $player_no = $game->getPlayerNoById($player_id) - 1;
        if ($player_no < 1) $player_no = $game->getPlayerCount();
        return $game->getPlayerIdByNo($player_no);
    }
}
