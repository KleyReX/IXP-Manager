<?php

namespace Tests\Api\Provisioning\Webhooks;

/*
 * Copyright (C) 2026 KleyReX. All Rights Reserved.
 *
 * This file is part of IXP Manager.
 *
 * IXP Manager is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, version v2.0 of the License.
 *
 * IXP Manager is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License v2.0
 * along with IXP Manager.  If not, see:
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

use PHPUnit\Framework\TestCase;

use IXP\Services\Webhooks\Differ;

/**
 * The `changes` of an update: JSON pointers, children by id, bookkeeping left out.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class DifferTest extends TestCase
{
    private function connection( array $overrides = [] ): array
    {
        return array_replace_recursive( [
            'id'                    => 501,
            'name'                  => 'ae1',
            'channelgroup'          => 1,
            'updated_at'            => '2026-11-24T10:00:00+00:00',
            'physical_interfaces'   => [
                [ 'id' => 7, 'status' => 1, 'speed' => 10000 ],
            ],
            'vlan_interfaces'       => [
                [ 'id' => 812, 'rsclient' => false, 'updated_at' => '2026-11-24T10:00:00+00:00',
                  'mac_addresses' => [ [ 'id' => 3, 'mac' => '001122334455' ] ],
                  'maxbgpprefix' => [ 'ipv4' => 100, 'ipv6' => null ] ],
            ],
        ], $overrides );
    }

    public function testIdenticalSnapshotsHaveNoChanges(): void
    {
        $this->assertSame( [], Differ::diff( $this->connection(), $this->connection() ) );
    }

    public function testAScalarChange(): void
    {
        $this->assertSame(
            [ [ 'path' => '/name', 'old' => 'ae1', 'new' => 'ae2' ] ],
            Differ::diff( $this->connection(), $this->connection( [ 'name' => 'ae2' ] ) )
        );
    }

    /**
     * Children are addressed by id, not by their position in the list.
     */
    public function testChildrenAreComparedById(): void
    {
        $after = $this->connection();
        $after[ 'vlan_interfaces' ][ 0 ][ 'rsclient' ]               = true;
        $after[ 'vlan_interfaces' ][ 0 ][ 'maxbgpprefix' ][ 'ipv6' ] = 20;

        $this->assertSame( [
            [ 'path' => '/vlan_interfaces/812/rsclient', 'old' => false, 'new' => true ],
            [ 'path' => '/vlan_interfaces/812/maxbgpprefix/ipv6', 'old' => null, 'new' => 20 ],
        ], Differ::diff( $this->connection(), $after ) );
    }

    public function testReorderedChildrenAreNoChange(): void
    {
        $before = $this->connection();
        $before[ 'physical_interfaces' ][] = [ 'id' => 8, 'status' => 1, 'speed' => 10000 ];

        $after = $before;
        $after[ 'physical_interfaces' ] = array_reverse( $after[ 'physical_interfaces' ] );

        $this->assertSame( [], Differ::diff( $before, $after ) );
    }

    /**
     * An added or removed child is one change carrying the whole child.
     */
    public function testAddedAndRemovedChildren(): void
    {
        $after = $this->connection();
        $after[ 'physical_interfaces' ][] = [ 'id' => 8, 'status' => 4, 'speed' => 10000 ];
        $after[ 'vlan_interfaces' ][ 0 ][ 'mac_addresses' ] = [];

        $this->assertSame( [
            [ 'path' => '/physical_interfaces/8', 'old' => null, 'new' => [ 'id' => 8, 'status' => 4, 'speed' => 10000 ] ],
            [ 'path' => '/vlan_interfaces/812/mac_addresses/3', 'old' => [ 'id' => 3, 'mac' => '001122334455' ], 'new' => null ],
        ], Differ::diff( $this->connection(), $after ) );

        $empty = $this->connection();
        $empty[ 'physical_interfaces' ] = [];

        $this->assertSame(
            [ [ 'path' => '/physical_interfaces/7', 'old' => [ 'id' => 7, 'status' => 1, 'speed' => 10000 ], 'new' => null ] ],
            Differ::diff( $this->connection(), $empty ),
            'removing the last child is still a change of that child'
        );
    }

    /**
     * Lists without ids are compared as one value.
     */
    public function testPlainListsAreOneValue(): void
    {
        $this->assertSame(
            [ [ 'path' => '/tags', 'old' => [ 'a', 'b' ], 'new' => [ 'a', 'c' ] ] ],
            Differ::diff( [ 'tags' => [ 'a', 'b' ] ], [ 'tags' => [ 'a', 'c' ] ] )
        );
    }

    /**
     * Only an integer or a string is an id. A list whose "ids" are booleans or floats is compared
     * as one value, rather than keyed by whatever PHP makes of them as array keys.
     */
    public function testListsWithOtherIdTypesAreOneValue(): void
    {
        $this->assertSame(
            [ [ 'path' => '/items', 'old' => [ [ 'id' => 2.0 ] ], 'new' => [ [ 'id' => 3.0 ] ] ] ],
            Differ::diff( [ 'items' => [ [ 'id' => 2.0 ] ] ], [ 'items' => [ [ 'id' => 3.0 ] ] ] )
        );

        $this->assertSame(
            [ [ 'path' => '/items', 'old' => [ [ 'id' => true ] ], 'new' => [ [ 'id' => false ] ] ] ],
            Differ::diff( [ 'items' => [ [ 'id' => true ] ] ], [ 'items' => [ [ 'id' => false ] ] ] )
        );
    }

    /**
     * updated_at is ignored wherever it is; resources add their own bookkeeping paths, with `*`
     * standing for one segment.
     */
    public function testIgnoredPaths(): void
    {
        $after = $this->connection( [ 'updated_at' => '2026-11-24T11:00:00+00:00', 'channelgroup' => 2 ] );
        $after[ 'vlan_interfaces' ][ 0 ][ 'updated_at' ] = '2026-11-24T11:00:00+00:00';

        $this->assertSame(
            [ [ 'path' => '/channelgroup', 'old' => 1, 'new' => 2 ] ],
            Differ::diff( $this->connection(), $after )
        );

        $this->assertSame( [], Differ::diff( $this->connection(), $after, [ '/channelgroup' ] ) );

        $before = [ 'custid' => 1, 'links' => [ [ 'id' => 4, 'privs' => 1, 'last_login_date' => null ] ] ];
        $after  = [ 'custid' => 2, 'links' => [ [ 'id' => 4, 'privs' => 2, 'last_login_date' => '2026-11-24' ] ] ];

        $this->assertSame(
            [ [ 'path' => '/links/4/privs', 'old' => 1, 'new' => 2 ] ],
            Differ::diff( $before, $after, [ '/custid', '/links/*/last_login_date' ] )
        );
    }

    /**
     * Type matters: a value which turns from "1" into 1 has changed.
     */
    public function testComparisonIsStrict(): void
    {
        $this->assertSame( [ [ 'path' => '/a', 'old' => '1', 'new' => 1 ] ], Differ::diff( [ 'a' => '1' ], [ 'a' => 1 ] ) );
        $this->assertSame( [ [ 'path' => '/a', 'old' => null, 'new' => false ] ], Differ::diff( [ 'a' => null ], [ 'a' => false ] ) );
    }

    /**
     * Keys are escaped per RFC 6901.
     */
    public function testPointerEscaping(): void
    {
        $this->assertSame(
            [ [ 'path' => '/a~1b/c~0d', 'old' => 1, 'new' => 2 ] ],
            Differ::diff( [ 'a/b' => [ 'c~d' => 1 ] ], [ 'a/b' => [ 'c~d' => 2 ] ] )
        );
    }

    /**
     * A nested object which appears or disappears - the billing record of a member, say.
     */
    public function testNestedObjectAppearing(): void
    {
        $this->assertSame(
            [ [ 'path' => '/billing', 'old' => null, 'new' => [ 'billingEmail' => 'x@example.com' ] ] ],
            Differ::diff( [ 'billing' => null ], [ 'billing' => [ 'billingEmail' => 'x@example.com' ] ] )
        );
    }
}
