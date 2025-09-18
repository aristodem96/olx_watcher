<!doctype html>
<html>
    <body>
        <h1>Зміна ціни на OLX</h1>

        <p>Оголошення: <strong>{{ $listing->url }}</strong></p>

        @if(!is_null($old))
            <p>Стара ціна: {{ $old }} {{$cur}}</p>
        @endif

        <p>Ціна: {{ $new }} {{$cur}}</p>

        @if(!empty($listing->url))
            <p><a href="{{ $listing->url }}">Перейти до оголошення</a></p>
        @endif
    </body>
</html>
