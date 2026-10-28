<?php
namespace Yablonsky\PerformanceSEO\AI\Transport;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Defensive parser for Aikeedo/Yablonsky AI SSE streams. */
final class SseParser {
    private const MAX_CONTENT_PARTS = 256;
    private const MAX_DEPTH = 8;

    /** @return array<int,array{event:string,data:string,id:string}> */
    public function parse( string $body ): array {
        $body = preg_replace( '/^\xEF\xBB\xBF/', '', $body );
        $body = str_replace( array( "\r\n", "\r" ), "\n", (string) $body );
        $blocks = preg_split( "/\n\n+/", trim( (string) $body ) );
        $events = array();
        if ( ! is_array( $blocks ) ) { return $events; }
        foreach ( $blocks as $block ) {
            if ( '' === trim( (string) $block ) ) { continue; }
            $event = 'message';
            $id = '';
            $data = array();
            foreach ( explode( "\n", (string) $block ) as $line ) {
                if ( '' === $line || ':' === substr( $line, 0, 1 ) ) { continue; }
                if ( 0 === strpos( $line, 'event:' ) ) { $event = trim( substr( $line, 6 ) ); }
                elseif ( 0 === strpos( $line, 'id:' ) ) { $id = trim( substr( $line, 3 ) ); }
                elseif ( 0 === strpos( $line, 'data:' ) ) { $data[] = ltrim( substr( $line, 5 ) ); }
            }
            if ( empty( $data ) ) { continue; }
            $events[] = array( 'event' => '' !== $event ? $event : 'message', 'data' => implode( "\n", $data ), 'id' => $id );
        }
        return $events;
    }

    /**
     * Writer normally ends with a document resource. Live transports can wrap the
     * resource or carry the final content in a document-labelled event, so event
     * semantics and structured streamed text are retained as recovery evidence.
     *
     * @return array{content:string,resource:array<string,mixed>,event_types:array<int,string>,has_final_document:bool,has_final_resource:bool,has_error:bool,error_code:string,error_message:string,event_count:int,body_bytes:int}
     */
    public function completionPayload( string $body ): array {
        $tokens = '';
        $resource = array();
        $document = array();
        $eventTypes = array();
        $eventCount = 0;
        $streamError = array( 'code' => '', 'message' => '' );

        foreach ( $this->parse( $body ) as $event ) {
            $eventCount++;
            $eventName = strtolower( trim( $event['event'] ) );
            if ( ! in_array( $eventName, $eventTypes, true ) ) { $eventTypes[] = $eventName; }

            $decoded = $this->decodeEventData( $event['data'] );
            $value = is_array( $decoded ) ? $decoded : null;

            if ( $this->isErrorEvent( $eventName, $value ) ) {
                $detail = $this->extractErrorDetail( $decoded, 0 );
                if ( '' === $streamError['code'] && '' !== $detail['code'] ) { $streamError['code'] = $detail['code']; }
                if ( '' === $streamError['message'] && '' !== $detail['message'] ) { $streamError['message'] = $detail['message']; }
                continue;
            }

            if ( is_array( $value ) ) {
                $foundDocument = $this->findResource( $value, 'document', 0 );
                if ( empty( $foundDocument ) && $this->isDocumentEvent( $eventName ) ) {
                    $foundDocument = $this->findDocumentPayload( $value, 0 );
                }
                if ( ! empty( $foundDocument ) ) {
                    if ( ! isset( $foundDocument['object'] ) ) { $foundDocument['object'] = 'document'; }
                    $document = $foundDocument;
                    $resource = $foundDocument;
                    continue;
                }

                $foundMessage = $this->findAssistantMessage( $value, 0 );
                if ( ! empty( $foundMessage ) ) { $resource = $foundMessage; }
            }

            if ( $this->isDeltaEvent( $eventName, $value ) ) {
                $tokens .= $this->extractDeltaText( null === $decoded ? $event['data'] : $decoded );
                continue;
            }

            // Some Writer gateways label streamed chunks generically. Only harvest
            // recognized textual fields; arbitrary status/resource metadata is ignored.
            if ( empty( $document ) && is_array( $value ) ) {
                $chunk = $this->extractPotentialText( $value, 0 );
                if ( '' !== $chunk ) { $tokens .= $chunk; }
            } elseif ( empty( $document ) && is_string( $decoded ) && ! $this->isTerminalMarker( $decoded ) ) {
                $tokens .= $decoded;
            }
        }

        $content = '';
        if ( ! empty( $document ) ) { $content = $this->resourceContent( $document ); }
        elseif ( ! empty( $resource ) ) { $content = $this->resourceContent( $resource ); }
        if ( '' === trim( $content ) ) { $content = $tokens; }

        return array(
            'content' => trim( $content ),
            'resource' => $resource,
            'event_types' => $eventTypes,
            'has_final_document' => ! empty( $document ),
            'has_final_resource' => ! empty( $resource ),
            'has_error' => '' !== $streamError['code'] || '' !== $streamError['message'],
            'error_code' => $streamError['code'],
            'error_message' => $streamError['message'],
            'event_count' => $eventCount,
            'body_bytes' => strlen( $body ),
        );
    }

    /** @param array<string|int,mixed>|null $value */
    private function isErrorEvent( string $eventName, ?array $value ): bool {
        if ( in_array( $eventName, array( 'error', 'safe-error', 'stream-error', 'message-error' ), true ) ) { return true; }
        if ( false !== strpos( $eventName, '.error' ) || false !== strpos( $eventName, '-error' ) || false !== strpos( $eventName, '_error' ) ) { return true; }
        if ( is_array( $value ) ) {
            $type = strtolower( trim( (string) ( $value['type'] ?? $value['event'] ?? '' ) ) );
            if ( in_array( $type, array( 'error', 'safe-error', 'stream-error', 'message-error' ), true ) ) { return true; }
            if ( false !== strpos( $type, '.error' ) || false !== strpos( $type, '-error' ) || false !== strpos( $type, '_error' ) ) { return true; }
        }
        return false;
    }

    /** @param mixed $value @return array{code:string,message:string} */
    private function extractErrorDetail( $value, int $depth ): array {
        if ( $depth > self::MAX_DEPTH ) { return array( 'code' => '', 'message' => '' ); }
        if ( is_string( $value ) ) { return array( 'code' => '', 'message' => $this->sanitizeErrorText( $value ) ); }
        if ( ! is_array( $value ) ) { return array( 'code' => '', 'message' => '' ); }

        $code = '';
        foreach ( array( 'code', 'error_code', 'reason', 'type' ) as $key ) {
            if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
                $candidate = sanitize_key( (string) $value[ $key ] );
                if ( '' !== $candidate && ! in_array( $candidate, array( 'error', 'safe-error', 'stream-error' ), true ) ) { $code = $candidate; break; }
            }
        }
        $message = '';
        foreach ( array( 'message', 'detail', 'description', 'text' ) as $key ) {
            if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
                $message = $this->sanitizeErrorText( (string) $value[ $key ] );
                if ( '' !== $message ) { break; }
            }
        }
        if ( isset( $value['error'] ) ) {
            if ( is_scalar( $value['error'] ) && '' === $message ) { $message = $this->sanitizeErrorText( (string) $value['error'] ); }
            elseif ( is_array( $value['error'] ) ) {
                $nested = $this->extractErrorDetail( $value['error'], $depth + 1 );
                if ( '' === $code ) { $code = $nested['code']; }
                if ( '' === $message ) { $message = $nested['message']; }
            }
        }
        foreach ( array( 'data', 'payload', 'response' ) as $key ) {
            if ( ( '' === $code || '' === $message ) && isset( $value[ $key ] ) && is_array( $value[ $key ] ) ) {
                $nested = $this->extractErrorDetail( $value[ $key ], $depth + 1 );
                if ( '' === $code ) { $code = $nested['code']; }
                if ( '' === $message ) { $message = $nested['message']; }
            }
        }
        return array( 'code' => $code, 'message' => $message );
    }

    private function sanitizeErrorText( string $text ): string {
        $text = trim( wp_strip_all_tags( $text, true ) );
        $text = (string) preg_replace( '/\s+/u', ' ', $text );
        $text = (string) preg_replace( '/(?i)(bearer\s+)[a-z0-9._-]+/', '$1[redacted]', $text );
        $text = (string) preg_replace( '/\beyJ[a-zA-Z0-9._-]{20,}\b/', '[redacted]', $text );
        $text = (string) preg_replace( '/\b(?:sk|key|api)[-_][a-zA-Z0-9_-]{12,}\b/i', '[redacted]', $text );
        if ( function_exists( 'mb_substr' ) ) { return mb_substr( $text, 0, 240, 'UTF-8' ); }
        return substr( $text, 0, 240 );
    }

    /** @return mixed */
    private function decodeEventData( string $data ) {
        $decoded = json_decode( $data, true );
        if ( JSON_ERROR_NONE !== json_last_error() ) { return $data; }
        // Some proxies/gateways JSON-encode an already JSON-encoded payload.
        for ( $i = 0; $i < 2 && is_string( $decoded ); $i++ ) {
            $inner = json_decode( $decoded, true );
            if ( JSON_ERROR_NONE !== json_last_error() ) { break; }
            $decoded = $inner;
        }
        return $decoded;
    }

    /** @param array<string|int,mixed> $value @return array<string,mixed> */
    private function findResource( array $value, string $object, int $depth ): array {
        if ( $depth > self::MAX_DEPTH ) { return array(); }
        $declared = strtolower( trim( (string) ( $value['object'] ?? $value['resource_type'] ?? $value['resourceType'] ?? '' ) ) );
        $type = strtolower( trim( (string) ( $value['type'] ?? '' ) ) );
        if ( $object === $declared || $object === $type ) { return $value; }
        foreach ( array( 'document', 'resource', 'data', 'result', 'payload', 'response', 'output' ) as $key ) {
            if ( isset( $value[ $key ] ) && is_array( $value[ $key ] ) ) {
                $found = $this->findResource( $value[ $key ], $object, $depth + 1 );
                if ( ! empty( $found ) ) { return $found; }
            }
        }
        foreach ( $value as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            $found = $this->findResource( $item, $object, $depth + 1 );
            if ( ! empty( $found ) ) { return $found; }
        }
        return array();
    }

    /** @param array<string|int,mixed> $value @return array<string,mixed> */
    private function findDocumentPayload( array $value, int $depth ): array {
        if ( $depth > self::MAX_DEPTH ) { return array(); }
        if ( array_key_exists( 'content', $value ) || array_key_exists( 'output_text', $value ) || array_key_exists( 'text', $value ) ) {
            return $value;
        }
        foreach ( array( 'document', 'resource', 'data', 'result', 'payload', 'response', 'output' ) as $key ) {
            if ( isset( $value[ $key ] ) && is_array( $value[ $key ] ) ) {
                $found = $this->findDocumentPayload( $value[ $key ], $depth + 1 );
                if ( ! empty( $found ) ) { return $found; }
            }
        }
        foreach ( $value as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            $found = $this->findDocumentPayload( $item, $depth + 1 );
            if ( ! empty( $found ) ) { return $found; }
        }
        return array();
    }

    /** @param array<string|int,mixed> $value @return array<string,mixed> */
    private function findAssistantMessage( array $value, int $depth ): array {
        if ( $depth > self::MAX_DEPTH ) { return array(); }
        $object = strtolower( (string) ( $value['object'] ?? '' ) );
        $role = strtolower( (string) ( $value['role'] ?? '' ) );
        if ( 'message' === $object && ( '' === $role || 'assistant' === $role ) ) { return $value; }
        if ( isset( $value['message'] ) && is_array( $value['message'] ) ) {
            $message = $value['message'];
            $messageRole = strtolower( (string) ( $message['role'] ?? '' ) );
            if ( '' === $messageRole || 'assistant' === $messageRole ) { return $message; }
        }
        foreach ( $value as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            $found = $this->findAssistantMessage( $item, $depth + 1 );
            if ( ! empty( $found ) ) { return $found; }
        }
        return array();
    }

    /** @param array<string,mixed> $resource */
    private function resourceContent( array $resource ): string {
        foreach ( array( 'content', 'output_text', 'output', 'text', 'message' ) as $key ) {
            if ( ! array_key_exists( $key, $resource ) ) { continue; }
            $candidate = $this->extractText( $resource[ $key ] );
            if ( '' !== trim( $candidate ) ) { return $candidate; }
        }
        return '';
    }

    /** @param mixed $value */
    private function extractDeltaText( $value ): string {
        if ( is_string( $value ) ) { return $value; }
        if ( ! is_array( $value ) ) { return ''; }
        foreach ( array( 'delta', 'token', 'text', 'content', 'value', 'output_text' ) as $key ) {
            if ( ! array_key_exists( $key, $value ) ) { continue; }
            $text = $this->extractText( $value[ $key ] );
            if ( '' !== $text ) { return $text; }
        }
        foreach ( array( 'data', 'payload', 'response', 'output' ) as $key ) {
            if ( isset( $value[ $key ] ) && is_array( $value[ $key ] ) ) {
                $text = $this->extractDeltaText( $value[ $key ] );
                if ( '' !== $text ) { return $text; }
            }
        }
        return '';
    }

    /** @param array<string|int,mixed> $value */
    private function extractPotentialText( array $value, int $depth ): string {
        if ( $depth > self::MAX_DEPTH ) { return ''; }
        foreach ( array( 'delta', 'token', 'text', 'content', 'value', 'output_text' ) as $key ) {
            if ( ! array_key_exists( $key, $value ) ) { continue; }
            $text = $this->extractText( $value[ $key ] );
            if ( '' !== $text ) { return $text; }
        }
        foreach ( array( 'data', 'payload', 'response', 'output', 'part' ) as $key ) {
            if ( isset( $value[ $key ] ) && is_array( $value[ $key ] ) ) {
                $text = $this->extractPotentialText( $value[ $key ], $depth + 1 );
                if ( '' !== $text ) { return $text; }
            }
        }
        return '';
    }

    /** @param mixed $value */
    private function extractText( $value ): string {
        if ( is_string( $value ) ) { return $value; }
        if ( is_scalar( $value ) ) { return (string) $value; }
        if ( ! is_array( $value ) ) { return ''; }
        $parts = array();
        $this->collectText( $value, $parts, 0 );
        return implode( '', $parts );
    }

    /** @param mixed $value @param array<int,string> $parts */
    private function collectText( $value, array &$parts, int $depth ): void {
        if ( count( $parts ) >= self::MAX_CONTENT_PARTS || $depth > self::MAX_DEPTH ) { return; }
        if ( is_string( $value ) ) { $parts[] = $value; return; }
        if ( ! is_array( $value ) ) { return; }
        foreach ( $value as $key => $item ) {
            if ( count( $parts ) >= self::MAX_CONTENT_PARTS ) { break; }
            if ( is_string( $item ) && in_array( (string) $key, array( 'text', 'content', 'delta', 'token', 'value', 'output_text' ), true ) ) {
                $parts[] = $item;
                continue;
            }
            if ( is_array( $item ) ) { $this->collectText( $item, $parts, $depth + 1 ); }
        }
    }

    /** @param array<string|int,mixed>|null $value */
    private function isDeltaEvent( string $eventName, ?array $value ): bool {
        if ( in_array( $eventName, array( 'token', 'delta', 'content', 'text', 'text-delta', 'content-delta', 'message-delta', 'response.output_text.delta', 'response-output-text-delta' ), true ) ) { return true; }
        if ( false !== strpos( $eventName, 'delta' ) ) { return true; }
        if ( is_array( $value ) ) {
            $type = strtolower( (string) ( $value['type'] ?? $value['event'] ?? '' ) );
            return false !== strpos( $type, 'delta' ) || in_array( $type, array( 'token', 'content', 'text' ), true );
        }
        return false;
    }

    private function isDocumentEvent( string $eventName ): bool {
        return 'document' === $eventName || false !== strpos( $eventName, 'document-' ) || false !== strpos( $eventName, 'document.' ) || false !== strpos( $eventName, 'document_' );
    }

    private function isTerminalMarker( string $value ): bool {
        $value = strtolower( trim( $value ) );
        return '' === $value || '[done]' === $value || 'done' === $value || '[completed]' === $value;
    }
}
