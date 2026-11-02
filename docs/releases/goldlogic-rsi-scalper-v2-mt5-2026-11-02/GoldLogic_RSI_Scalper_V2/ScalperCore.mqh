#ifndef GOLDLOGIC_SCALPER_CORE_MQH
#define GOLDLOGIC_SCALPER_CORE_MQH

// Pure, causal strategy math. No indicators, account state, orders, or clock reads.
// The caller supplies exactly 203 oldest-first CLOSED M5 bars with UTC times.
// The caller establishes closure/freshness; this interface has no current clock.
#define GL_REQUIRED_BARS 203

struct GLSignal
  {
   int      side;                 // +1 BUY, -1 SELL, 0 HOLD
   double   entry;
   double   stop;
   double   target;               // Full exit at TP1; no TP2/partial/BE/trail.
   double   risk;                 // PHP risk_per_unit, rounded to four places.
   double   atr;                  // Displayed PHP indicator precision: four places.
   double   rsi;                  // Displayed PHP indicator precision: two places.
   double   fast;
   double   slow;
   double   trend_now;            // SMA200, canonical eight-place precision.
   double   trend_prior;          // SMA200 three ACTIVE bars earlier.
   datetime decision_utc;
  };

void GLResetSignal(GLSignal &signal)
  {
   signal.side=0;
   signal.entry=0.0;
   signal.stop=0.0;
   signal.target=0.0;
   signal.risk=0.0;
   signal.atr=0.0;
   signal.rsi=0.0;
   signal.fast=0.0;
   signal.slow=0.0;
   signal.trend_now=0.0;
   signal.trend_prior=0.0;
   signal.decision_utc=0;
  }

// Positive decimal precisions used by this strategy only (0..8).
// Compare the ORIGINAL value against its decimal half boundary, rather than
// rounding a binary scaled value. This matches PHP8.5 round(..., HALF_UP),
// including 4124.36155 -> 4124.3616 and the symmetric negative case.
// Independently expressed from the documented rounding semantics; edge behavior
// crosschecked with php-src/PHP-8.5/ext/standard/math.c and golden PHP fixtures.
double GLPhpRound(double value,int places)
  {
   if(!MathIsValidNumber(value) || value==0.0 || places<0 || places>8)
      return value;
   double scale=1.0;
   for(int i=0;i<places;i++)
      scale*=10.0;
   double magnitude=MathAbs(value);
   double whole=MathFloor(magnitude*scale);
   // Recover an exact decimal grid point lost when scaling the binary input.
   if((whole+1.0)/scale==magnitude)
      whole+=1.0;
   if(whole>=1e16)
      return value;
   if(magnitude>=(whole+0.5)/scale)
      whole+=1.0;
   double rounded=whole/scale;
   return value<0.0 ? -rounded : rounded;
  }

datetime GLCalendarUtc(int year,int month,int day,int hour,int minute,int second=0)
  {
   MqlDateTime parts;
   ZeroMemory(parts);
   parts.year=year;
   parts.mon=month;
   parts.day=day;
   parts.hour=hour;
   parts.min=minute;
   parts.sec=second;
   return StructToTime(parts);
  }

bool GLSupportedUtc(datetime utc)
  {
   MqlDateTime parts;
   return TimeToStruct(utc,parts) && parts.year>=2007 && parts.year<=2099;
  }

int GLNthSunday(int year,int month,int ordinal)
  {
   MqlDateTime first;
   if(!TimeToStruct(GLCalendarUtc(year,month,1,0,0),first))
      return 0;
   return 1+(7-first.day_of_week)%7+(ordinal-1)*7;
  }

int GLLastSunday(int year,int month)
  {
   int nextYear=year;
   int nextMonth=month+1;
   if(nextMonth==13)
     {
      nextMonth=1;
      nextYear++;
     }
   MqlDateTime last;
   datetime lastDate=(datetime)((long)GLCalendarUtc(nextYear,nextMonth,1,0,0)-86400);
   if(!TimeToStruct(lastDate,last))
      return 0;
   return last.day-last.day_of_week;
  }

// Rule1: US transitions expressed in UTC, valid for 2007..2099.
// Rule2: EU transitions expressed in UTC. These select a BROKER offset schedule;
// they do not claim that every broker follows either convention.
bool GLDaylightUtc(datetime utc,int dst_rule,bool &daylight)
  {
   daylight=false;
   MqlDateTime parts;
   if(!TimeToStruct(utc,parts) || parts.year<2007 || parts.year>2099)
      return false;
   if(dst_rule==0)
      return true;
   datetime begin=0;
   datetime finish=0;
   if(dst_rule==1)
     {
      begin=GLCalendarUtc(parts.year,3,GLNthSunday(parts.year,3,2),7,0);
      finish=GLCalendarUtc(parts.year,11,GLNthSunday(parts.year,11,1),6,0);
     }
   else if(dst_rule==2)
     {
      begin=GLCalendarUtc(parts.year,3,GLLastSunday(parts.year,3),1,0);
      finish=GLCalendarUtc(parts.year,10,GLLastSunday(parts.year,10),1,0);
     }
   else
      return false;
   daylight=(utc>=begin && utc<finish);
   return true;
  }

// Invert the wall-clock timestamp by testing both possible UTC candidates.
// A spring gap has no candidate; an autumn overlap has two. Both fail closed.
// Rule0 uses winter_minutes only. Offsets mean server minus UTC, in minutes.
bool GLBrokerToUtc(datetime server,int winter_minutes,int summer_minutes,int dst_rule,datetime &utc)
  {
   utc=0;
   if(winter_minutes<-840 || winter_minutes>840 || dst_rule<0 || dst_rule>2)
      return false;
   if(dst_rule!=0 && (summer_minutes<-840 || summer_minutes>840))
      return false;
   datetime winterUtc=(datetime)((long)server-(long)winter_minutes*60);
   bool winterDaylight=false;
   bool winterValid=GLDaylightUtc(winterUtc,dst_rule,winterDaylight) && !winterDaylight;
   if(dst_rule==0)
     {
      if(winterValid)
         utc=winterUtc;
      return winterValid;
     }
   datetime summerUtc=(datetime)((long)server-(long)summer_minutes*60);
   bool summerDaylight=false;
   bool summerValid=GLDaylightUtc(summerUtc,dst_rule,summerDaylight) && summerDaylight;
   if(winterValid==summerValid)
      return false;
   utc=winterValid ? winterUtc : summerUtc;
   return true;
  }

// Gold: Sunday18:00 NY through Friday17:00 NY, Mon-Thu17:00-18:00 closed.
// Regular sessions only: broker holidays/early closes remain the caller's job.
bool GLGoldOpen(datetime utc)
  {
   bool daylight=false;
   if(!GLDaylightUtc(utc,1,daylight))
      return false;
   datetime local=(datetime)((long)utc+(daylight ? -4 : -5)*3600);
   MqlDateTime ny;
   if(!TimeToStruct(local,ny))
      return false;
   int minute=ny.hour*60+ny.min;
   if(ny.day_of_week==6 || (ny.day_of_week==0 && minute<18*60) || (ny.day_of_week==5 && minute>=17*60))
      return false;
   if(ny.day_of_week>=1 && ny.day_of_week<=4 && minute>=17*60 && minute<18*60)
      return false;
   return true;
  }

// Entry permission is determined at the source M5 candle CLOSE, in UTC.
bool GLEntryWindow(datetime utc)
  {
   MqlDateTime parts;
   if(!GLSupportedUtc(utc) || !TimeToStruct(utc,parts))
      return false;
   return parts.day_of_week>=1 && parts.day_of_week<=5 && parts.hour>=7 && parts.hour<17;
  }

double GLCloseMean(const MqlRates &bars[],int first,int count)
  {
   double total=0.0;
   for(int i=first;i<first+count;i++)
      total+=bars[i].close;
   return total/count;
  }

// False means malformed/insufficient history. True includes a valid HOLD.
// Input gaps are permitted, as in PHP: the 203 bars are ACTIVE bars, not a
// continuous 1,015-minute wall-clock block. No source bar is silently discarded.
bool GLEvaluate(const MqlRates &bars[],GLSignal &signal,string &reason)
  {
   GLResetSignal(signal);
   reason="";
   if(ArraySize(bars)!=GL_REQUIRED_BARS || ArrayGetAsSeries(bars))
     {
      reason="Require exactly203 oldest-first UTC M5 closed trading-session bars.";
      return false;
     }
   for(int i=0;i<GL_REQUIRED_BARS;i++)
     {
      if(!GLSupportedUtc(bars[i].time) || (long)bars[i].time%300!=0 || (i>0 && bars[i].time<=bars[i-1].time))
        {
         reason="M5 UTC opens must be supported, aligned, strictly ascending and unique.";
         return false;
        }
      if(!MathIsValidNumber(bars[i].open) || !MathIsValidNumber(bars[i].high) || !MathIsValidNumber(bars[i].low)
         || !MathIsValidNumber(bars[i].close) || bars[i].open<=0 || bars[i].high<=0 || bars[i].low<=0 || bars[i].close<=0
         || bars[i].high<MathMax(MathMax(bars[i].open,bars[i].close),bars[i].low)
         || bars[i].low>MathMin(bars[i].open,bars[i].close))
        {
         reason="Each source candle requires positive finite consistent OHLC.";
         return false;
        }
      if(!GLGoldOpen(bars[i].time) || !GLGoldOpen((datetime)((long)bars[i].time+299)))
        {
         reason="Closed-session source bars must be excluded before selecting the window.";
         return false;
        }
     }
   int last=GL_REQUIRED_BARS-1;
   signal.decision_utc=(datetime)((long)bars[last].time+300);
   double fastNow=GLCloseMean(bars,GL_REQUIRED_BARS-9,9);
   double fastPrior=GLCloseMean(bars,GL_REQUIRED_BARS-10,9);
   double slowNow=GLCloseMean(bars,GL_REQUIRED_BARS-21,21);
   double slowPrior=GLCloseMean(bars,GL_REQUIRED_BARS-22,21);
   double gains=0.0;
   double losses=0.0;
   double trueRangeSum=0.0;
   for(int i=GL_REQUIRED_BARS-14;i<GL_REQUIRED_BARS;i++)
     {
      double delta=bars[i].close-bars[i-1].close;
      if(delta>=0.0)
         gains+=delta;
      else
         losses+=MathAbs(delta);
      double range=MathMax(bars[i].high-bars[i].low,MathMax(MathAbs(bars[i].high-bars[i-1].close),MathAbs(bars[i].low-bars[i-1].close)));
      trueRangeSum+=range;
     }
   double averageGain=gains/14.0;
   double averageLoss=losses/14.0;
   double rsiNow=averageLoss==0.0 ? 100.0 : 100.0-(100.0/(1.0+averageGain/averageLoss));
   double atrNow=trueRangeSum/14.0;
   if(atrNow<=0.0)
      atrNow=MathMax(0.5,bars[last].high-bars[last].low);
   signal.rsi=GLPhpRound(rsiNow,2);
   signal.atr=GLPhpRound(atrNow,4);
   signal.fast=GLPhpRound(fastNow,4);
   signal.slow=GLPhpRound(slowNow,4);
   signal.trend_now=GLPhpRound(GLCloseMean(bars,3,200),8);
   signal.trend_prior=GLPhpRound(GLCloseMean(bars,0,200),8);
   bool bullishCross=fastPrior<=slowPrior && fastNow>slowNow;
   bool bearishCross=fastPrior>=slowPrior && fastNow<slowNow;
   int side=0;
   if(bullishCross && rsiNow>50.0 && rsiNow<70.0)
      side=1;
   else if(bearishCross && rsiNow<50.0 && rsiNow>30.0)
      side=-1;
   if(side==0)
     {
      reason="No confirmed SMA9/21 and RSI entry crossover.";
      return true;
     }
   if(!GLEntryWindow(signal.decision_utc))
     {
      reason="Decision is outside07:00-17:00 UTC weekdays.";
      return true;
     }
   double entry=bars[last].close;
   bool up=entry>signal.trend_now && signal.trend_now>signal.trend_prior;
   bool down=entry<signal.trend_now && signal.trend_now<signal.trend_prior;
   if((side==1 && !up) || (side==-1 && !down))
     {
      reason="Crossover direction does not agree with price and SMA200 slope.";
      return true;
     }
   double recentLow=bars[GL_REQUIRED_BARS-12].low;
   double recentHigh=bars[GL_REQUIRED_BARS-12].high;
   for(int i=GL_REQUIRED_BARS-11;i<GL_REQUIRED_BARS;i++)
     {
      recentLow=MathMin(recentLow,bars[i].low);
      recentHigh=MathMax(recentHigh,bars[i].high);
     }
   double stop=side==1 ? MathMin(entry-atrNow*1.2,recentLow-atrNow*0.20)
                         : MathMax(entry+atrNow*1.2,recentHigh+atrNow*0.20);
   double risk=MathMax(side==1 ? entry-stop : stop-entry,atrNow*0.8);
   stop=side==1 ? entry-risk : entry+risk;
   double target=side==1 ? entry+risk*1.5 : entry-risk*1.5;
   signal.side=side;
   signal.entry=GLPhpRound(entry,4);
   signal.stop=GLPhpRound(stop,4);
   signal.target=GLPhpRound(target,4);
   signal.risk=GLPhpRound(risk,4);
   reason=side==1 ? "Confirmed trend-session BUY; full TP1 or original stop." : "Confirmed trend-session SELL; full TP1 or original stop.";
   return true;
  }

#endif // GOLDLOGIC_SCALPER_CORE_MQH
