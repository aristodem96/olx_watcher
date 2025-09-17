<!doctype html>
<html>
    <body>
        <h1>Зміна ціни на OLX</h1>

        <p>Оголошення: <strong>{{ $listing->title }}</strong></p>

        @if(!is_null($old))
            <p>Було: {{ number_format($old, 0, '.', ' ') }}</p>
        @endif

        <p>Стало: {{ number_format($new, 0, '.', ' ') }}</p>

        @if(!empty($listing->url))
            <p><a href="{{ $listing->url }}">Перейти до оголошення</a></p>
        @endif
    </body>
</html>
