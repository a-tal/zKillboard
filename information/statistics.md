# Statistics
<hr/>
### Kills
{kills} total kills processed<br/>
######*These numbers are updated hourly...*

### Points
```
Calculation:
        $vicpoints = Points::getPoints($victim["groupID"]);
        $vicpoints += $kill["total_price"] / 10000000;
        $maxpoints = round($vicpoints * 1.2);

        $invpoints = 0;
        foreach ($involved as $inv)
        {
                $invpoints += Points::getPoints($inv["groupID"]);
        }

        $gankfactor = $vicpoints / ($vicpoints + $invpoints);
        $points = ceil($vicpoints * ($gankfactor / 0.75));
        if ($points > $maxpoints) $points = $maxpoints;
        $points = round($points, 0);
```

### Point System
{pointsystem}
