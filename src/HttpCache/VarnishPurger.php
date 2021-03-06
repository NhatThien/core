<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Core\HttpCache;

use GuzzleHttp\ClientInterface;

/**
 * Purges Varnish.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 *
 * @experimental
 */
final class VarnishPurger implements PurgerInterface
{
    private $clients;
    private $maxHeaderLength;

    /**
     * @param ClientInterface[] $clients
     */
    public function __construct(array $clients, int $maxHeaderLength = 7500)
    {
        $this->clients = $clients;
        $this->maxHeaderLength = $maxHeaderLength;
    }

    /**
     * Calculate how many tags fit into the header.
     *
     * This assumes that the tags are separated by one character.
     *
     * From https://github.com/FriendsOfSymfony/FOSHttpCache/blob/2.8.0/src/ProxyClient/HttpProxyClient.php#L137
     *
     * @param string[] $escapedTags
     * @param string   $glue        The concatenation string to use
     *
     * @return int Number of tags per tag invalidation request
     */
    private function determineTagsPerHeader(array $escapedTags, string $glue): int
    {
        if (mb_strlen(implode($glue, $escapedTags)) < $this->maxHeaderLength) {
            return \count($escapedTags);
        }
        /*
         * estimate the amount of tags to invalidate by dividing the max
         * header length by the largest tag (minus the glue length)
         */
        $tagsize = max(array_map('mb_strlen', $escapedTags));

        return (int) floor($this->maxHeaderLength / ($tagsize + \strlen($glue))) ?: 1;
    }

    /**
     * {@inheritdoc}
     */
    public function purge(array $iris)
    {
        if (!$iris) {
            return;
        }

        $chunkSize = $this->determineTagsPerHeader($iris, '|');

        $irisChunks = array_chunk($iris, $chunkSize);
        foreach ($irisChunks as $irisChunk) {
            $this->purgeRequest($irisChunk);
        }
    }

    private function purgeRequest(array $iris)
    {
        // Create the regex to purge all tags in just one request
        $parts = array_map(function ($iri) {
            return sprintf('(^|\,)%s($|\,)', preg_quote($iri));
        }, $iris);

        $regex = \count($parts) > 1 ? sprintf('(%s)', implode(')|(', $parts)) : array_shift($parts);

        foreach ($this->clients as $client) {
            $client->request('BAN', '', ['headers' => ['ApiPlatform-Ban-Regex' => $regex]]);
        }
    }
}
