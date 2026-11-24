<?php

namespace IXP\Services\Webhooks;

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

/**
 * The difference between two snapshots of a resource, as a list of changed paths.
 *
 * Each change is `{path, old, new}`, the path a JSON pointer (RFC 6901) into the event's `data`.
 * Lists of objects carrying an `id` - the physical and VLAN interfaces of a connection, the MACs
 * of a VLAN interface, a user's member links, a contact's roles - are compared by id rather than
 * by position, and the id is the path segment: `/vlan_interfaces/812/rsclient`. A child added or
 * removed is one change whose `old` or `new` is null and whose other side is the whole child.
 * Any other list is compared as one value.
 *
 * This is also what decides whether there is an event at all: a unit of work whose writes leave
 * every reported field as it was produces no changes, and so nothing is sent. A receiver that
 * writes back exactly what it was told therefore cannot start a ping-pong.
 *
 * `updated_at` is ignored at any depth - it moves with every save and says nothing a receiver can
 * act on. Each resource adds its own bookkeeping fields (Snapshots::ignored()).
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Services\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class Differ
{
    /**
     * Keys ignored wherever they occur.
     *
     * @var array<int,string>
     */
    public const array IGNORED_KEYS = [ 'updated_at' ];

    /**
     * The changes between two snapshots.
     *
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @param  array<int,string>    $ignore  JSON pointers to leave out; `*` stands for one segment
     *
     * @return array<int,array{path:string,old:mixed,new:mixed}>
     */
    public static function diff( array $before, array $after, array $ignore = [] ): array
    {
        $patterns = array_map( fn( string $p ) => self::pattern( $p ), $ignore );
        $changes  = [];

        self::walk( $before, $after, '', $patterns, $changes );

        return $changes;
    }

    /**
     * Compare two values at a path, appending what differs.
     *
     * @param  mixed                $old
     * @param  mixed                $new
     * @param  string               $path
     * @param  array<int,string>    $patterns
     * @param  array<int,array>     $changes
     *
     * @return void
     */
    private static function walk( mixed $old, mixed $new, string $path, array $patterns, array &$changes ): void
    {
        if( $path !== '' && self::ignored( $path, $patterns ) ) {
            return;
        }

        if( is_array( $old ) && is_array( $new ) ) {
            $oldList = array_is_list( $old );
            $newList = array_is_list( $new );

            if( $oldList && $newList ) {
                if( !self::keyedById( $old ) || !self::keyedById( $new ) ) {
                    if( $old !== $new ) {
                        $changes[] = [ 'path' => $path, 'old' => $old, 'new' => $new ];
                    }

                    return;
                }

                $old = self::byId( $old );
                $new = self::byId( $new );
            } else if( $oldList !== $newList && $old !== [] && $new !== [] ) {
                // a list on one side and an object on the other: not comparable field by field
                $changes[] = [ 'path' => $path, 'old' => $old, 'new' => $new ];
                return;
            }

            foreach( array_keys( $old + $new ) as $key ) {
                self::walk( $old[ $key ] ?? null, $new[ $key ] ?? null, $path . '/' . self::escape( (string)$key ), $patterns, $changes );
            }

            return;
        }

        if( $old !== $new ) {
            $changes[] = [ 'path' => $path, 'old' => $old, 'new' => $new ];
        }
    }

    /**
     * Whether every element of a list is an object with a distinct integer or string `id`.
     *
     * An empty list qualifies, so that the first child added to an empty collection is reported
     * as that child rather than as the whole new list.
     *
     * @param  array<int,mixed>  $list
     *
     * @return bool
     */
    private static function keyedById( array $list ): bool
    {
        $seen = [];

        foreach( $list as $item ) {
            if( !is_array( $item ) || !isset( $item[ 'id' ] ) || !( is_int( $item[ 'id' ] ) || is_string( $item[ 'id' ] ) ) || isset( $seen[ $item[ 'id' ] ] ) ) {
                return false;
            }

            $seen[ $item[ 'id' ] ] = true;
        }

        return true;
    }

    /**
     * A list of objects keyed by their ids.
     *
     * @param  array<int,array<string,mixed>>  $list
     *
     * @return array<int|string,array<string,mixed>>
     */
    private static function byId( array $list ): array
    {
        $out = [];

        foreach( $list as $item ) {
            $out[ $item[ 'id' ] ] = $item;
        }

        return $out;
    }

    /**
     * Whether a path is left out of the comparison.
     *
     * @param  string             $path
     * @param  array<int,string>  $patterns
     *
     * @return bool
     */
    private static function ignored( string $path, array $patterns ): bool
    {
        $last = substr( $path, strrpos( $path, '/' ) + 1 );

        if( in_array( $last, self::IGNORED_KEYS, true ) ) {
            return true;
        }

        foreach( $patterns as $pattern ) {
            if( preg_match( $pattern, $path ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A regular expression for an ignore pointer, `*` matching exactly one segment.
     *
     * @param  string  $pointer
     *
     * @return string
     */
    private static function pattern( string $pointer ): string
    {
        $segments = array_map(
            fn( string $s ) => $s === '*' ? '[^/]+' : preg_quote( $s, '#' ),
            explode( '/', $pointer )
        );

        return '#^' . implode( '/', $segments ) . '$#';
    }

    /**
     * Escape a key for use as a JSON pointer segment.
     *
     * @param  string  $key
     *
     * @return string
     */
    private static function escape( string $key ): string
    {
        return str_replace( [ '~', '/' ], [ '~0', '~1' ], $key );
    }
}
